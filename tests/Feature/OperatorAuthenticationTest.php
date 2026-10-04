<?php

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(LazilyRefreshDatabase::class);

test('guests are redirected from the operator panel to login', function () {
    $this->get(route('operator.dashboard'))->assertRedirect(route('login'));
});

test('the guest home page redirects to the canonical login page', function () {
    $this->get('/')->assertRedirect('/login');
});

test('authenticated operators are redirected to the dashboard instead of home or login', function (string $path) {
    $this->actingAs(User::factory()->create());

    $this->get($path)->assertRedirect(route('operator.dashboard'));
})->with(['/', '/login', '/operator/login']);

test('the legacy guest login URL redirects to the canonical login page', function () {
    $this->get('/operator/login')->assertRedirect('/login');
});

test('an operator can sign in and there is no registration route', function () {
    $operator = User::factory()->create([
        'email' => 'operator@example.test',
        'password' => 'password',
    ]);

    $this->get(route('login'))->assertSee('Вход оператора')->assertDontSeeText('M-Social')
        ->assertSeeHtml('x-on:submit="normalizePassword()"')
        ->assertSeeHtml('x-ref="password" x-on:input="normalizePassword()"');
    $this->get('/')->assertRedirect(route('login'));

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

test('ignores whitespace throughout the login password without changing its stored hash', function (string $password, string $canonicalPassword) {
    $operator = User::factory()->create(['password' => $canonicalPassword]);
    $originalHash = $operator->password;

    $this->post(route('operator.login.store'), [
        'email' => $operator->email,
        'password' => $password,
    ])->assertRedirect(route('operator.dashboard'))->assertSessionHasNoErrors();

    $this->assertAuthenticatedAs($operator);
    expect($operator->refresh()->password)->toBe($originalHash);
})->with([
    'unchanged' => ['Correct-Pass.2026!', 'Correct-Pass.2026!'],
    'leading and trailing spaces' => ['  Correct-Pass.2026!  ', 'Correct-Pass.2026!'],
    'internal spaces' => ['Correct- Pass.20 26!', 'Correct-Pass.2026!'],
    'tabs and newlines' => ["\tCorrect-\nPass.2026!\r\n", 'Correct-Pass.2026!'],
    'nonbreaking and narrow spaces' => ["\u{A0}Correct-\u{202F}Pass.2026!\u{A0}", 'Correct-Pass.2026!'],
    'Unicode separators' => ["Correct-\u{2003}Pass.20\u{3000}26!", 'Correct-Pass.2026!'],
    'byte order mark from clipboard' => ["\u{FEFF}Correct-Pass.2026!", 'Correct-Pass.2026!'],
    'Unicode letters and symbols' => [' Пароль- 2026!🔒 ', 'Пароль-2026!🔒'],
]);

test('rejects an empty password after whitespace removal without flashing it to the session', function (string $password) {
    $operator = User::factory()->create();
    $originalHash = $operator->password;

    $this->post(route('operator.login.store'), [
        'email' => $operator->email,
        'password' => $password,
    ])->assertSessionHasErrors(['password' => 'The password field is required.'])
        ->assertSessionMissingInput('password');

    $this->assertGuest();
    expect($operator->refresh()->password)->toBe($originalHash);
})->with([
    'empty' => '',
    'ASCII whitespace only' => " \t\r\n\v\f ",
    'Unicode whitespace only' => "\u{A0}\u{202F}\u{2003}\u{3000}",
    'clipboard mark only' => "\u{FEFF}",
    'invalid UTF-8' => "\xFF",
]);

test('keeps password type validation when normalizing the login input', function (mixed $password) {
    $operator = User::factory()->create();

    $this->post(route('operator.login.store'), [
        'email' => $operator->email,
        'password' => $password,
    ])->assertSessionHasErrors(['password' => 'The password field must be a string.'])
        ->assertSessionMissingInput('password');

    $this->assertGuest();
})->with([
    'array' => [['password']],
    'integer' => [123456],
]);

test('does not ignore non-whitespace password characters or case', function (string $password) {
    $operator = User::factory()->create(['password' => 'Correct-Pass.2026!']);
    $originalHash = $operator->password;

    $this->post(route('operator.login.store'), [
        'email' => $operator->email,
        'password' => $password,
    ])->assertSessionHasErrors(['email' => 'Указаны неверные учётные данные.'])
        ->assertSessionMissingInput('password');

    $this->assertGuest();
    expect($operator->refresh()->password)->toBe($originalHash);
})->with([
    'wrong case' => ' correct-Pass.2026! ',
    'missing punctuation' => 'Correct- Pass.2026',
    'another non-whitespace character' => 'Correct-Pass.2026? ',
]);

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

test('bootstraps an operator with the same whitespace normalization as the login form', function () {
    config()->set('support.operator', [
        'email' => 'bootstrap@example.test',
        'password' => " \tCorrect-\u{A0}Pass.20 26!\n",
    ]);

    $this->seed();
    $operator = User::query()->sole();

    expect(Hash::check('Correct-Pass.2026!', $operator->password))->toBeTrue();
    $this->post(route('operator.login.store'), [
        'email' => $operator->email,
        'password' => 'Correct- Pass.20 26!',
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
    'ASCII whitespace password' => ['bootstrap@example.test', " \t\r\n "],
    'Unicode whitespace password' => ['bootstrap@example.test', "\u{A0}\u{202F}\u{2003}"],
    'clipboard mark password' => ['bootstrap@example.test', "\u{FEFF}"],
    'invalid UTF-8 password' => ['bootstrap@example.test', "\xFF"],
    'null-byte-only password' => ['bootstrap@example.test', "\0"],
]);
