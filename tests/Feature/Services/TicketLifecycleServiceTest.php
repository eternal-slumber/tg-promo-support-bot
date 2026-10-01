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

test('moves an open ticket to waiting and back to open', function () {
    $ticket = Ticket::factory()->create();
    $service = new TicketLifecycleService;

    $waitingTicket = $service->waitForUser($ticket);
    $openTicket = $service->markUnresolved($waitingTicket);

    expect($waitingTicket->status)->toBe(TicketStatus::WaitingForUser)
        ->and($waitingTicket->waiting_since)->not->toBeNull()
        ->and($openTicket->status)->toBe(TicketStatus::Open)
        ->and($openTicket->waiting_since)->toBeNull()
        ->and($openTicket->closed_at)->toBeNull()
        ->and($openTicket->close_reason)->toBeNull();
});

test('closes an open or waiting ticket manually', function (string $initialState) {
    $ticket = $initialState === TicketStatus::Open->value
        ? Ticket::factory()->create()
        : Ticket::factory()->waitingForUser()->create();
    $service = new TicketLifecycleService;

    $closedTicket = $service->closeManually($ticket);

    expect($closedTicket->status)->toBe(TicketStatus::Closed)
        ->and($closedTicket->close_reason)->toBe(TicketCloseReason::OperatorClosed)
        ->and($closedTicket->closed_at)->not->toBeNull();
})->with([
    'open ticket' => TicketStatus::Open->value,
    'waiting ticket' => TicketStatus::WaitingForUser->value,
]);

test('closes a waiting ticket when the participant confirms resolution', function () {
    $ticket = Ticket::factory()->waitingForUser()->create();

    $closedTicket = (new TicketLifecycleService)->resolve($ticket);

    expect($closedTicket->status)->toBe(TicketStatus::Closed)
        ->and($closedTicket->close_reason)->toBe(TicketCloseReason::UserConfirmed);
});

test('rejects transitions from an unexpected state', function (string $operation) {
    $ticket = Ticket::factory()->create();
    $service = new TicketLifecycleService;

    expect(fn () => $service->{$operation}($ticket))->toThrow(DomainException::class);
})->with([
    'resolve an open ticket' => 'resolve',
    'mark an open ticket unresolved' => 'markUnresolved',
]);

test('closed is terminal and repeated closing is rejected', function (string $operation) {
    $ticket = Ticket::factory()->closed()->create();
    $service = new TicketLifecycleService;

    expect(fn () => $service->{$operation}($ticket))->toThrow(DomainException::class);
})->with([
    'wait for user' => 'waitForUser',
    'manual close' => 'closeManually',
    'resolve' => 'resolve',
    'mark unresolved' => 'markUnresolved',
]);

test('closed tickets are not returned as active', function () {
    $ticket = Ticket::factory()->closed()->create();

    expect((new TicketLifecycleService)->activeFor($ticket->participant))->toBeNull();
});
