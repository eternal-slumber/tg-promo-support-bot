<?php

use App\Enums\TicketCloseReason;
use App\Enums\TicketStatus;
use App\Models\TelegramParticipant;
use App\Models\Ticket;
use App\Services\TicketLifecycleService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

test('creates one active ticket and returns it for the participant', function () {
    $participant = TelegramParticipant::factory()->create();
    $service = new TicketLifecycleService;

    $ticket = $service->create($participant, 'participant_specific');

    expect($ticket->status)->toBe(TicketStatus::Open)
        ->and($ticket->escalation_reason)->toBe('participant_specific')
        ->and($service->activeFor($participant)->is($ticket))->toBeTrue();
});

test('rejects a second active ticket', function () {
    $participant = TelegramParticipant::factory()->create();
    $service = new TicketLifecycleService;
    $service->create($participant);

    expect(fn () => $service->create($participant))->toThrow(DomainException::class);
});

test('marks an open ticket resolved and reopens it without closing', function () {
    $ticket = Ticket::factory()->create();
    $service = new TicketLifecycleService;

    $resolvedTicket = $service->resolve($ticket);
    $openTicket = $service->reopen($resolvedTicket);

    expect($resolvedTicket->status)->toBe(TicketStatus::Resolved)
        ->and($resolvedTicket->resolved_since)->not->toBeNull()
        ->and($openTicket->status)->toBe(TicketStatus::Open)
        ->and($openTicket->resolved_since)->toBeNull()
        ->and($openTicket->closed_at)->toBeNull()
        ->and($openTicket->close_reason)->toBeNull();
});

test('closes an open or resolved ticket manually', function (string $initialState) {
    $ticket = $initialState === TicketStatus::Open->value
        ? Ticket::factory()->create()
        : Ticket::factory()->resolved()->create();
    $service = new TicketLifecycleService;

    $closedTicket = $service->closeManually($ticket);

    expect($closedTicket->status)->toBe(TicketStatus::Closed)
        ->and($closedTicket->close_reason)->toBe(TicketCloseReason::OperatorClosed)
        ->and($closedTicket->closed_at)->not->toBeNull();
})->with([
    'open ticket' => TicketStatus::Open->value,
    'resolved ticket' => TicketStatus::Resolved->value,
]);

test('resolving an open ticket does not close it', function () {
    $ticket = Ticket::factory()->create();

    $resolvedTicket = (new TicketLifecycleService)->resolve($ticket);

    expect($resolvedTicket->status)->toBe(TicketStatus::Resolved)
        ->and($resolvedTicket->close_reason)->toBeNull()
        ->and($resolvedTicket->closed_at)->toBeNull();
});

test('rejects automatic closure of an open ticket', function () {
    $ticket = Ticket::factory()->create();
    $service = new TicketLifecycleService;

    expect(fn () => $service->closeAutomatically($ticket))->toThrow(DomainException::class);
});

test('closed is terminal and repeated closing is rejected', function (string $operation) {
    $ticket = Ticket::factory()->closed()->create();
    $service = new TicketLifecycleService;

    expect(fn () => $service->{$operation}($ticket))->toThrow(DomainException::class);
})->with([
    'manual close' => 'closeManually',
    'resolve' => 'resolve',
    'reopen' => 'reopen',
    'automatic close' => 'closeAutomatically',
]);

test('closed tickets are not returned as active', function () {
    $ticket = Ticket::factory()->closed()->create();

    expect((new TicketLifecycleService)->activeFor($ticket->participant))->toBeNull();
});
