<?php

use App\Enums\DeliveryStatus;
use App\Enums\MessageAuthor;
use App\Enums\MessageDirection;
use App\Enums\TicketCloseReason;
use App\Enums\TicketStatus;
use App\Models\Message;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(LazilyRefreshDatabase::class);

test('reopens legacy waiting tickets while preserving history delivery states and response timestamps', function () {
    $this->freezeTime();
    $ticket = Ticket::factory()->resolved()->create(['first_operator_replied_at' => now()->subMinute()]);
    $closed = Ticket::factory()->closed(TicketCloseReason::UserConfirmed)->create();
    $question = Message::factory()->for($ticket->participant, 'participant')->for($ticket)->create(['body' => 'Сохранённый вопрос']);
    $reply = Message::factory()->for($ticket->participant, 'participant')->for($ticket)->create([
        'direction' => MessageDirection::Outbound,
        'author' => MessageAuthor::Operator,
        'delivery_status' => DeliveryStatus::Failed,
        'body' => 'Сохранённый ответ',
    ]);
    $decouple = require database_path('migrations/2026_10_03_200139_decouple_ticket_resolution_from_replies.php');
    $decouple->down();
    $migration = require database_path('migrations/2026_10_03_141903_simplify_ticket_lifecycle.php');
    $migration->down();
    expect(DB::table('tickets')->where('id', $ticket->id)->value('status'))->toBe('waiting_for_user');

    $migration->up();
    $decouple->up();

    expect($ticket->refresh()->status)->toBe(TicketStatus::Open)
        ->and($ticket->resolved_since)->toBeNull()
        ->and($ticket->first_operator_replied_at?->getTimestamp())->toBe(now()->subMinute()->getTimestamp());
    expect($question->refresh()->body)->toBe('Сохранённый вопрос')
        ->and($question->ticket_id)->toBe($ticket->id);
    expect($reply->refresh()->body)->toBe('Сохранённый ответ')
        ->and($reply->delivery_status)->toBe(DeliveryStatus::Failed);
    expect($closed->refresh()->status)->toBe(TicketStatus::Closed)
        ->and($closed->close_reason)->toBe(TicketCloseReason::UserConfirmed);
    expect(Schema::getColumnListing('tickets'))->toContain('resolved_since')->not->toContain('waiting_since', 'input_revision');
    expect(Schema::getColumnListing('messages'))->not->toContain('resolves_ticket', 'operator_input_revision');
});

test('removes reply resolution intent without cancelling existing operator replies', function () {
    $this->freezeTime();
    $migration = require database_path('migrations/2026_10_03_200139_decouple_ticket_resolution_from_replies.php');
    $migration->down();
    $ticket = Ticket::factory()->resolved()->create(['first_operator_replied_at' => now()->subMinute()]);
    $reply = Message::factory()->for($ticket->participant, 'participant')->for($ticket)->create([
        'direction' => MessageDirection::Outbound, 'author' => MessageAuthor::Operator,
        'delivery_status' => DeliveryStatus::Pending,
    ]);
    DB::table('messages')->where('id', $reply->id)->update(['resolves_ticket' => true]);

    $migration->up();

    expect($ticket->refresh()->status)->toBe(TicketStatus::Open)
        ->and($ticket->resolved_since)->toBeNull()
        ->and($ticket->first_operator_replied_at?->getTimestamp())->toBe(now()->subMinute()->getTimestamp());
    expect($reply->refresh()->delivery_status)->toBe(DeliveryStatus::Pending);
    expect(Schema::hasColumn('messages', 'resolves_ticket'))->toBeFalse();
});
