<?php

use App\Enums\TicketCloseReason;
use App\Enums\TicketStatus;
use App\Jobs\AutoCloseTicket;
use App\Models\Ticket;
use App\Services\TicketLifecycleService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

test('auto close closes the matching waiting ticket', function () {
    $ticket = Ticket::factory()->resolved()->create();

    autoClose($ticket, $ticket->resolved_since->toISOString());

    expect($ticket->refresh()->status)->toBe(TicketStatus::Closed)
        ->and($ticket->close_reason)->toBe(TicketCloseReason::AutoClosed);
});

test('a stale auto close job does nothing after the ticket reopens', function () {
    $ticket = Ticket::factory()->resolved()->create();
    $resolvedSince = $ticket->resolved_since->toISOString();
    app(TicketLifecycleService::class)->reopen($ticket);

    autoClose($ticket, $resolvedSince);

    expect($ticket->refresh()->status)->toBe(TicketStatus::Open)
        ->and($ticket->close_reason)->toBeNull();
});

test('a repeated auto close job does nothing after automatic closure', function () {
    $ticket = Ticket::factory()->resolved()->create();
    $resolvedSince = $ticket->resolved_since->toISOString();

    autoClose($ticket, $resolvedSince);
    $closedAt = $ticket->refresh()->closed_at;
    autoClose($ticket, $resolvedSince);

    expect($ticket->refresh()->status)->toBe(TicketStatus::Closed)
        ->and($ticket->close_reason)->toBe(TicketCloseReason::AutoClosed)
        ->and($ticket->closed_at)->toEqual($closedAt);
});

test('a repeated auto close job does not override a manual close', function () {
    $ticket = Ticket::factory()->resolved()->create();
    $resolvedSince = $ticket->resolved_since->toISOString();
    app(TicketLifecycleService::class)->closeManually($ticket);

    autoClose($ticket, $resolvedSince);
    autoClose($ticket, $resolvedSince);

    expect($ticket->refresh()->status)->toBe(TicketStatus::Closed)
        ->and($ticket->close_reason)->toBe(TicketCloseReason::OperatorClosed);
});

test('a serialized legacy waiting timer cannot close a newly resolved ticket', function () {
    $ticket = Ticket::factory()->resolved()->create();
    $payload = serialize(new AutoCloseTicket($ticket->id, $ticket->resolved_since->toISOString()));
    $legacyTimer = unserialize(str_replace('s:13:"resolvedSince";', 's:12:"waitingSince";', $payload));

    $legacyTimer->handle(app(TicketLifecycleService::class));

    expect($ticket->refresh()->status)->toBe(TicketStatus::Resolved)
        ->and($ticket->close_reason)->toBeNull();
});

function autoClose(Ticket $ticket, string $resolvedSince): void
{
    (new AutoCloseTicket($ticket->id, $resolvedSince))->handle(app(TicketLifecycleService::class));
}
