<?php

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

test('guests are redirected from the operator panel to login', function () {
    $this->get(route('operator.dashboard'))->assertRedirect(route('login'));
});

test('an operator can sign in and there is no registration route', function () {
    $operator = User::factory()->create([
        'email' => 'operator@example.test',
        'password' => 'password',
    ]);

    $this->post(route('operator.login.store'), [
        'email' => $operator->email,
        'password' => 'password',
    ])->assertRedirect(route('operator.dashboard'));

    $this->assertAuthenticatedAs($operator);

    $this->get(route('operator.dashboard'))
        ->assertOk()
        ->assertSee('Обращения поддержки');

    $this->get('/register')
        ->assertNotFound();
});
