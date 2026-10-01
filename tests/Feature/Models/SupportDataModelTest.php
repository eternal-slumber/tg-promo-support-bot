<?php

use App\Enums\DeliveryStatus;
use App\Enums\MessageAuthor;
use App\Enums\MessageDirection;
use App\Enums\SupportDecisionType;
use App\Enums\TicketStatus;
use App\Models\Message;
use App\Models\SupportDecision;
use App\Models\TelegramParticipant;
use App\Models\TelegramUpdate;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(LazilyRefreshDatabase::class);

test('models expose casts and relationships', function () {
    $participant = TelegramParticipant::factory()->create();
    $update = TelegramUpdate::factory()->for($participant, 'participant')->create();
    $ticket = Ticket::factory()->for($participant, 'participant')->waitingForUser()->create();
    $operator = User::factory()->create();
    $message = Message::factory()
        ->for($participant, 'participant')
        ->for($ticket)
        ->for($update, 'telegramUpdate')
        ->for($operator, 'operator')
        ->create([
            'direction' => MessageDirection::Outbound,
            'author' => MessageAuthor::Operator,
            'delivery_status' => DeliveryStatus::Pending,
            'sensitive_data_redacted' => true,
            'redaction_types' => ['payment_card'],
        ]);
    $decision = SupportDecision::factory()->for($message)->create([
        'type' => SupportDecisionType::Mixed,
        'structured_output' => ['type' => 'mixed'],
    ]);

    expect($update->received_at)->toBeInstanceOf(DateTimeInterface::class)
        ->and($ticket->status)->toBe(TicketStatus::WaitingForUser)
        ->and($ticket->waiting_since)->toBeInstanceOf(DateTimeInterface::class)
        ->and($message->direction)->toBe(MessageDirection::Outbound)
        ->and($message->author)->toBe(MessageAuthor::Operator)
        ->and($message->delivery_status)->toBe(DeliveryStatus::Pending)
        ->and($message->sensitive_data_redacted)->toBeTrue()
        ->and($message->redaction_types)->toBe(['payment_card'])
        ->and($decision->type)->toBe(SupportDecisionType::Mixed)
        ->and($decision->structured_output)->toBe(['type' => 'mixed'])
        ->and($participant->updates->sole()->is($update))->toBeTrue()
        ->and($participant->tickets->sole()->is($ticket))->toBeTrue()
        ->and($participant->messages->sole()->is($message))->toBeTrue()
        ->and($ticket->participant->is($participant))->toBeTrue()
        ->and($ticket->messages->sole()->is($message))->toBeTrue()
        ->and($message->telegramUpdate->is($update))->toBeTrue()
        ->and($message->operator->is($operator))->toBeTrue()
        ->and($message->decision->is($decision))->toBeTrue()
        ->and($operator->messages->sole()->is($message))->toBeTrue()
        ->and($decision->message->is($message))->toBeTrue();
});

test('telegram update id is unique', function () {
    $update = TelegramUpdate::factory()->create(['update_id' => 42]);

    expect(fn () => TelegramUpdate::factory()->create(['update_id' => $update->update_id]))
        ->toThrow(QueryException::class);
});

test('a message has at most one persisted decision', function () {
    $message = Message::factory()->create();
    SupportDecision::factory()->for($message)->create();

    expect(fn () => SupportDecision::factory()->for($message)->create())
        ->toThrow(QueryException::class);
});

test('postgresql enforces one active ticket per participant', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql')
        ->and(DB::table('pg_indexes')
            ->where('tablename', 'tickets')
            ->where('indexname', 'tickets_one_active_per_participant')
            ->exists())->toBeTrue();

    $participant = TelegramParticipant::factory()->create();
    Ticket::factory()->for($participant, 'participant')->create();

    expect(fn () => Ticket::factory()->for($participant, 'participant')->waitingForUser()->create())
        ->toThrow(QueryException::class);
});

test('messages schema stores only the redacted body', function () {
    expect(Schema::getColumnListing('messages'))
        ->toContain('body', 'sensitive_data_redacted', 'redaction_types')
        ->not->toContain('raw_body', 'raw_text', 'original_body');
});
