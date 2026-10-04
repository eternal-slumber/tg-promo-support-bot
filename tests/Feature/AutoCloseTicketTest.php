<?php

use App\Enums\TicketCloseReason;
use App\Enums\TicketStatus;
use App\Jobs\AutoCloseTicket;
use App\Models\Ticket;
use App\Services\TicketLifecycleService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

test('auto close closes the matching resolved ticket', function () {
    $this->freezeTime();
    $ticket = Ticket::factory()->resolved()->create();
    $this->travel(24)->hours();

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

    $this->travel(24)->hours();
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

test('auto close cannot close a resolved ticket before its timeout', function () {
    $this->freezeTime();
    $ticket = Ticket::factory()->resolved()->create();
    $this->travel(23)->hours();

    autoClose($ticket, $ticket->resolved_since->toISOString());

    expect($ticket->refresh()->status)->toBe(TicketStatus::Resolved)
        ->and($ticket->closed_at)->toBeNull();
});

test('an old timer cannot close a new resolution within the same second', function () {
    Queue::fake([AutoCloseTicket::class]);
    $this->travelTo(now()->setMicrosecond(100000));
    $ticket = Ticket::factory()->create();
    $lifecycle = app(TicketLifecycleService::class);
    $first = $lifecycle->resolve($ticket);
    $oldTimer = new AutoCloseTicket($ticket->id, $first->resolved_since->toISOString());
    $lifecycle->reopen($ticket);
    $this->travelTo(now()->addMicroseconds(100000));
    $second = $lifecycle->resolve($ticket);
    expect($second->fresh()->resolved_since->toISOString())->not->toBe($first->resolved_since->toISOString());
    $this->travel(24)->hours();

    $oldTimer->handle($lifecycle);

    expect($ticket->refresh()->status)->toBe(TicketStatus::Resolved);
    (new AutoCloseTicket($ticket->id, $second->resolved_since->toISOString()))->handle($lifecycle);
    expect($ticket->refresh()->status)->toBe(TicketStatus::Closed);
});
