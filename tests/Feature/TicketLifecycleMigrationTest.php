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
    $migration = require database_path('migrations/2026_10_03_141903_simplify_ticket_lifecycle.php');
    $migration->down();
    expect(DB::table('tickets')->where('id', $ticket->id)->value('status'))->toBe('waiting_for_user');

    $migration->up();

    expect($ticket->refresh()->status)->toBe(TicketStatus::Open)
        ->and($ticket->resolved_since)->toBeNull()
        ->and($ticket->first_operator_replied_at?->getTimestamp())->toBe(now()->subMinute()->getTimestamp());
    expect($question->refresh()->body)->toBe('Сохранённый вопрос')
        ->and($question->ticket_id)->toBe($ticket->id);
    expect($reply->refresh()->body)->toBe('Сохранённый ответ')
        ->and($reply->delivery_status)->toBe(DeliveryStatus::Failed)
        ->and($reply->resolves_ticket)->toBeFalse();
    expect($closed->refresh()->status)->toBe(TicketStatus::Closed)
        ->and($closed->close_reason)->toBe(TicketCloseReason::UserConfirmed);
    expect(Schema::getColumnListing('tickets'))->toContain('resolved_since')->not->toContain('waiting_since', 'input_revision');
    expect(Schema::getColumnListing('messages'))->toContain('resolves_ticket')->not->toContain('operator_input_revision');
});
