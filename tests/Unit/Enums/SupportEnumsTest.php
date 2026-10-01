<?php

use App\Enums\DeliveryStatus;
use App\Enums\MessageAuthor;
use App\Enums\MessageDirection;
use App\Enums\SupportDecisionType;
use App\Enums\TicketCloseReason;
use App\Enums\TicketStatus;

test('support enums expose the persisted values', function (string $enum, array $values) {
    expect(array_column($enum::cases(), 'value'))->toBe($values);
})->with([
    'ticket statuses' => [TicketStatus::class, ['open', 'waiting_for_user', 'closed']],
    'ticket close reasons' => [TicketCloseReason::class, ['user_confirmed', 'auto_closed', 'operator_closed']],
    'message directions' => [MessageDirection::class, ['inbound', 'outbound']],
    'message authors' => [MessageAuthor::class, ['participant', 'bot', 'operator', 'system']],
    'delivery statuses' => [DeliveryStatus::class, ['pending', 'sent', 'failed']],
    'support decision types' => [SupportDecisionType::class, ['answer', 'escalate', 'mixed', 'refuse']],
]);

test('ticket status permits only designed transitions', function (TicketStatus $from, TicketStatus $to, bool $allowed) {
    expect($from->canTransitionTo($to))->toBe($allowed);
})->with([
    'open to waiting' => [TicketStatus::Open, TicketStatus::WaitingForUser, true],
    'open to closed' => [TicketStatus::Open, TicketStatus::Closed, true],
    'open to open' => [TicketStatus::Open, TicketStatus::Open, false],
    'waiting to open' => [TicketStatus::WaitingForUser, TicketStatus::Open, true],
    'waiting to closed' => [TicketStatus::WaitingForUser, TicketStatus::Closed, true],
    'waiting to waiting' => [TicketStatus::WaitingForUser, TicketStatus::WaitingForUser, false],
    'closed to open' => [TicketStatus::Closed, TicketStatus::Open, false],
    'closed to waiting' => [TicketStatus::Closed, TicketStatus::WaitingForUser, false],
    'closed to closed' => [TicketStatus::Closed, TicketStatus::Closed, false],
]);
