<?php

use App\Enums\TicketCloseReason;
use App\Enums\TicketStatus;
use App\Jobs\AutoCloseTicket;
use App\Models\Ticket;
use App\Services\TicketLifecycleService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

test('auto close closes the matching waiting ticket', function () {
    $ticket = Ticket::factory()->waitingForUser()->create();

    autoClose($ticket, $ticket->waiting_since->toISOString());

    expect($ticket->refresh()->status)->toBe(TicketStatus::Closed)
        ->and($ticket->close_reason)->toBe(TicketCloseReason::AutoClosed);
});

test('a stale auto close job does nothing after the ticket reopens', function () {
    $ticket = Ticket::factory()->waitingForUser()->create();
    $waitingSince = $ticket->waiting_since->toISOString();
    app(TicketLifecycleService::class)->markUnresolved($ticket);

    autoClose($ticket, $waitingSince);

    expect($ticket->refresh()->status)->toBe(TicketStatus::Open)
        ->and($ticket->close_reason)->toBeNull();
});

test('a repeated auto close job does nothing after automatic closure', function () {
    $ticket = Ticket::factory()->waitingForUser()->create();
    $waitingSince = $ticket->waiting_since->toISOString();

    autoClose($ticket, $waitingSince);
    $closedAt = $ticket->refresh()->closed_at;
    autoClose($ticket, $waitingSince);

    expect($ticket->refresh()->status)->toBe(TicketStatus::Closed)
        ->and($ticket->close_reason)->toBe(TicketCloseReason::AutoClosed)
        ->and($ticket->closed_at)->toEqual($closedAt);
});

test('a repeated auto close job does not override a manual close', function () {
    $ticket = Ticket::factory()->waitingForUser()->create();
    $waitingSince = $ticket->waiting_since->toISOString();
    app(TicketLifecycleService::class)->closeManually($ticket);

    autoClose($ticket, $waitingSince);
    autoClose($ticket, $waitingSince);

    expect($ticket->refresh()->status)->toBe(TicketStatus::Closed)
        ->and($ticket->close_reason)->toBe(TicketCloseReason::OperatorClosed);
});

function autoClose(Ticket $ticket, string $waitingSince): void
{
    (new AutoCloseTicket($ticket->id, $waitingSince))->handle(app(TicketLifecycleService::class));
}
