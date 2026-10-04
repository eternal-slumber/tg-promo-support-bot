<?php

use App\Http\Controllers\AuthenticatedSessionController;
use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', fn (Request $request): RedirectResponse => redirect()
    ->route($request->user() === null ? 'login' : 'operator.dashboard'));

Route::post('/telegram/webhook', TelegramWebhookController::class)->name('telegram.webhook');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::get('/operator/login', fn (): RedirectResponse => redirect()->route('login'));
    Route::post('/operator/login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('operator.login.store');
});

Route::middleware('auth')->group(function (): void {
    Route::view('/operator', 'operator.dashboard')->name('operator.dashboard');
    Route::post('/operator/logout', [AuthenticatedSessionController::class, 'destroy'])->name('operator.logout');
});
