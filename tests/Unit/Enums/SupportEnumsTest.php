<?php

use App\Enums\DeliveryStatus;
use App\Enums\MessageAuthor;
use App\Enums\MessageDirection;
use App\Enums\SupportDecisionType;
use App\Enums\TicketCloseReason;
use App\Enums\TicketEscalationReason;
use App\Enums\TicketStatus;

test('support enums expose the persisted values', function (string $enum, array $values) {
    expect(array_column($enum::cases(), 'value'))->toBe($values);
})->with([
    'ticket statuses' => [TicketStatus::class, ['open', 'resolved', 'closed']],
    'ticket close reasons' => [TicketCloseReason::class, ['user_confirmed', 'auto_closed', 'operator_closed']],
    'ticket escalation reasons' => [TicketEscalationReason::class, ['llm_failure', 'participant_specific', 'not_in_rules', 'mixed_request']],
    'message directions' => [MessageDirection::class, ['inbound', 'outbound']],
    'message authors' => [MessageAuthor::class, ['participant', 'bot', 'operator', 'system']],
    'delivery statuses' => [DeliveryStatus::class, ['pending', 'sent', 'failed', 'cancelled']],
    'support decision types' => [SupportDecisionType::class, ['answer', 'escalate', 'mixed', 'refuse']],
]);

test('ticket status permits only designed transitions', function (TicketStatus $from, TicketStatus $to, bool $allowed) {
    expect($from->canTransitionTo($to))->toBe($allowed);
})->with([
    'open to waiting' => [TicketStatus::Open, TicketStatus::Resolved, true],
    'open to closed' => [TicketStatus::Open, TicketStatus::Closed, true],
    'open to open' => [TicketStatus::Open, TicketStatus::Open, false],
    'waiting to open' => [TicketStatus::Resolved, TicketStatus::Open, true],
    'waiting to closed' => [TicketStatus::Resolved, TicketStatus::Closed, true],
    'waiting to waiting' => [TicketStatus::Resolved, TicketStatus::Resolved, false],
    'closed to open' => [TicketStatus::Closed, TicketStatus::Open, false],
    'closed to waiting' => [TicketStatus::Closed, TicketStatus::Resolved, false],
    'closed to closed' => [TicketStatus::Closed, TicketStatus::Closed, false],
]);
