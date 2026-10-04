<?php

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(LazilyRefreshDatabase::class);

test('guests are redirected from the operator panel to login', function () {
    $this->get(route('operator.dashboard'))->assertRedirect(route('login'));
});

test('an operator can sign in and there is no registration route', function () {
    $operator = User::factory()->create([
        'email' => 'operator@example.test',
        'password' => 'password',
    ]);

    $this->get(route('login'))->assertSee('Вход оператора')->assertDontSeeText('M-Social');
    $this->get('/')->assertSee('Поддержка промо-акции')->assertDontSeeText('M-Social');

    $this->post(route('operator.login.store'), [
        'email' => $operator->email,
        'password' => 'password',
    ])->assertRedirect(route('operator.dashboard'));

    $this->assertAuthenticatedAs($operator);

    $this->get(route('operator.dashboard'))
        ->assertOk()
        ->assertSee('Обращения поддержки')
        ->assertDontSeeText('M-Social');

    $this->get('/register')
        ->assertNotFound();
});

test('bootstraps one operator from configured credentials without resetting its password', function () {
    config()->set('support.operator', ['email' => 'bootstrap@example.test', 'password' => 'test-only-bootstrap-password']);

    $this->seed();
    $operator = User::query()->sole();
    $originalHash = $operator->password;

    expect($operator->email)->toBe('bootstrap@example.test')
        ->and($originalHash)->not->toBe('test-only-bootstrap-password')
        ->and(Hash::check('test-only-bootstrap-password', $originalHash))->toBeTrue();

    config()->set('support.operator.password', 'different-test-only-password');
    $this->seed();

    expect(User::query()->count())->toBe(1)
        ->and($operator->refresh()->password)->toBe($originalHash);

    $this->post(route('operator.login.store'), [
        'email' => 'bootstrap@example.test',
        'password' => 'test-only-bootstrap-password',
    ])->assertRedirect(route('operator.dashboard'));

    $this->assertAuthenticatedAs($operator);
});

test('fails operator bootstrap without valid credentials', function (?string $email, ?string $password) {
    config()->set('support.operator', ['email' => $email, 'password' => $password]);

    expect(fn () => $this->seed())->toThrow(RuntimeException::class, 'Operator bootstrap requires')
        ->and(User::query()->count())->toBe(0);
})->with([
    'missing email' => [null, 'test-only-password'],
    'invalid email' => ['invalid', 'test-only-password'],
    'missing password' => ['bootstrap@example.test', null],
    'blank password' => ['bootstrap@example.test', ''],
]);
