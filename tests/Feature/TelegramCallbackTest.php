<?php

use App\Enums\TicketStatus;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    config()->set('telegram.webhook_secret', 'test-webhook-secret');
    $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-webhook-secret');
});

test('legacy inline callbacks cannot change any ticket or delivery address', function (TicketStatus $status, string $action) {
    $ticket = Ticket::factory()->create(['status' => $status, 'resolved_since' => $status === TicketStatus::Resolved ? now() : null]);
    $participant = $ticket->participant;
    $before = $ticket->refresh()->toArray();
    Queue::fake();
    Http::preventStrayRequests();
    $payload = legacyInlineUpdate($ticket, $action);

    $this->postJson(route('telegram.webhook'), $payload)->assertOk()->assertExactJson(['status' => 'ignored']);
    $this->postJson(route('telegram.webhook'), $payload)->assertOk()->assertExactJson(['status' => 'ignored']);

    expect($ticket->refresh()->toArray())->toEqual($before)
        ->and($participant->refresh()->chat_id)->toBe($payload['callback_query']['message']['chat']['id'] - 1)
        ->and(Schema::hasColumn('messages', 'pending_callback_action'))->toBeFalse();
    $this->assertDatabaseCount('telegram_participants', 1);
    $this->assertDatabaseCount('tickets', 1);
    $this->assertDatabaseCount('telegram_updates', 0);
    $this->assertDatabaseCount('messages', 0);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with(TicketStatus::cases())->with(['resolved', 'unresolved']);

test('legacy callbacks still require webhook authentication', function (?string $secret) {
    $ticket = Ticket::factory()->resolved()->create();
    Queue::fake();
    Http::preventStrayRequests();
    $this->flushHeaders();
    if ($secret !== null) {
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $secret);
    }

    $this->postJson(route('telegram.webhook'), legacyInlineUpdate($ticket, 'resolved'))->assertForbidden();

    expect($ticket->refresh()->status)->toBe(TicketStatus::Resolved);
    $this->assertDatabaseCount('telegram_updates', 0);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with(['missing' => [null], 'wrong' => ['wrong-secret']]);

/** @return array<string, mixed> */
function legacyInlineUpdate(Ticket $ticket, string $action): array
{
    return [
        'update_id' => 3001,
        'callback_query' => [
            'id' => 'old-inline-button',
            'from' => ['id' => $ticket->participant->telegram_user_id],
            'message' => ['chat' => ['id' => $ticket->participant->chat_id + 1, 'type' => 'private']],
            'data' => "{$action}:{$ticket->id}:123",
        ],
    ];
}
