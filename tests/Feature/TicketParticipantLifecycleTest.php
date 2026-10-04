<?php

use App\Enums\DeliveryStatus;
use App\Enums\MessageDirection;
use App\Enums\TicketCloseReason;
use App\Enums\TicketStatus;
use App\Jobs\AutoCloseTicket;
use App\Jobs\DeliverTelegramMessage;
use App\Jobs\ProcessIncomingMessage;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use App\Services\OperatorReplyService;
use App\Services\TicketLifecycleService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    config()->set('telegram.webhook_secret', 'test-webhook-secret');
    $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-webhook-secret');
});

test('participant text stays in the same ticket and reopens it without AI or automatic closure', function (string $text, TicketStatus $status, ?TicketCloseReason $reason) {
    $this->freezeTime();
    $ticket = Ticket::factory()->resolved()->create();
    $resolvedSince = $ticket->resolved_since->toISOString();
    Queue::fake();
    Http::preventStrayRequests();
    $payload = ticketParticipantUpdate($ticket, 11001, $text);

    $this->postJson(route('telegram.webhook'), $payload)->assertOk()->assertJsonPath('status', 'accepted');
    $this->postJson(route('telegram.webhook'), $payload)->assertOk()->assertJsonPath('status', 'duplicate');

    $inbound = Message::query()->sole();
    expect($inbound->ticket_id)->toBe($ticket->id)
        ->and($inbound->body)->toBe($text)
        ->and($inbound->direction)->toBe(MessageDirection::Inbound)
        ->and($ticket->refresh()->status)->toBe($status)
        ->and($ticket->close_reason)->toBe($reason)
        ->and($ticket->resolved_since)->toBeNull();
    $this->assertDatabaseCount('tickets', 1);
    $this->assertDatabaseCount('telegram_updates', 1);
    $this->travel(25)->hours();
    (new AutoCloseTicket($ticket->id, $resolvedSince))->handle(app(TicketLifecycleService::class));
    expect($ticket->refresh()->status)->toBe($status)
        ->and($ticket->close_reason)->toBe($reason);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with([
    'former confirmation text' => ['Проблема решена', TicketStatus::Open, null],
    'former unresolved text' => ['Не решило', TicketStatus::Open, null],
    'continuation' => ['Не помогло, ошибка осталась', TicketStatus::Open, null],
    'other question' => ['А где посмотреть номер?', TicketStatus::Open, null],
]);

test('a participant message permits the next operator reply and the old timer cannot close its new cycle', function () {
    $this->freezeTime();
    $ticket = Ticket::factory()->create();
    $operator = User::factory()->create();
    Queue::fake();
    Http::preventStrayRequests();
    Http::fake(['*sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 12001]])]);
    $replies = app(OperatorReplyService::class);
    $replyA = $replies->create($operator, $ticket, 'Ответ A');
    app()->call([new DeliverTelegramMessage($replyA->id), 'handle']);
    app(TicketLifecycleService::class)->resolve($ticket);
    $oldTimer = Queue::pushed(AutoCloseTicket::class)->sole();

    $this->postJson(route('telegram.webhook'), ticketParticipantUpdate($ticket, 11002, 'Не помогло'))->assertOk();
    expect($ticket->refresh()->status)->toBe(TicketStatus::Open);
    $this->travel(1)->seconds();
    $replyB = $replies->create($operator, $ticket, 'Ответ B');
    app()->call([new DeliverTelegramMessage($replyB->id), 'handle']);
    app(TicketLifecycleService::class)->resolve($ticket);
    $currentTimer = Queue::pushed(AutoCloseTicket::class)->last();
    expect($replyB->refresh()->delivery_status)->toBe(DeliveryStatus::Sent);
    $this->travel(24)->hours();
    $oldTimer->handle(app(TicketLifecycleService::class));
    expect($ticket->refresh()->status)->toBe(TicketStatus::Resolved);
    $currentTimer->handle(app(TicketLifecycleService::class));
    expect($ticket->refresh()->status)->toBe(TicketStatus::Closed)
        ->and($ticket->close_reason)->toBe(TicketCloseReason::AutoClosed);
    Queue::assertPushed(AutoCloseTicket::class, 2);
    Http::assertSentCount(2);
});

test('former feedback text after closure is processed as a new ordinary question', function () {
    $ticket = Ticket::factory()->closed()->create();
    Queue::fake();
    Http::preventStrayRequests();

    $this->postJson(route('telegram.webhook'), ticketParticipantUpdate($ticket, 11003, 'Проблема решена'))->assertOk()->assertJsonPath('status', 'accepted');

    expect($ticket->refresh()->status)->toBe(TicketStatus::Closed);
    $this->assertDatabaseCount('messages', 1);
    expect(Message::query()->sole()->ticket_id)->toBeNull();
    Queue::assertPushed(ProcessIncomingMessage::class, 1);
    Http::assertNothingSent();
});

test('text sent before delivery stays in the open conversation', function () {
    $ticket = Ticket::factory()->create();
    Queue::fake();
    Http::preventStrayRequests();
    Http::fake(['*sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 12002]])]);
    $reply = app(OperatorReplyService::class)->create(User::factory()->create(), $ticket, 'Ответ');

    $this->postJson(route('telegram.webhook'), ticketParticipantUpdate($ticket, 11004, 'Проблема решена'))->assertOk();
    expect($ticket->refresh()->status)->toBe(TicketStatus::Open);
    $inbound = Message::query()->where('direction', MessageDirection::Inbound)->sole();
    app()->call([new DeliverTelegramMessage($reply->id), 'handle']);

    expect($ticket->refresh()->status)->toBe(TicketStatus::Open)
        ->and($ticket->close_reason)->toBeNull()
        ->and($inbound->body)->toBe('Проблема решена')
        ->and($reply->refresh()->delivery_status)->toBe(DeliveryStatus::Sent);
    Queue::assertNotPushed(AutoCloseTicket::class);
    Queue::assertPushed(DeliverTelegramMessage::class, 1);
    Http::assertSentCount(1);
});

test('participant text can only reopen the senders own resolved ticket', function () {
    $ownerTicket = Ticket::factory()->resolved()->create();
    $senderTicket = Ticket::factory()->resolved()->create();
    Queue::fake();
    Http::preventStrayRequests();
    $payload = ticketParticipantUpdate($senderTicket, 11005, 'Проблема решена');
    $payload['message']['ticket_id'] = $ownerTicket->id;

    $this->postJson(route('telegram.webhook'), $payload)->assertOk();

    expect($ownerTicket->refresh()->status)->toBe(TicketStatus::Resolved)
        ->and($senderTicket->refresh()->status)->toBe(TicketStatus::Open)
        ->and($senderTicket->close_reason)->toBeNull()
        ->and(Message::query()->sole()->ticket_id)->toBe($senderTicket->id);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

/** @return array<string, mixed> */
function ticketParticipantUpdate(Ticket $ticket, int $updateId, string $text): array
{
    return [
        'update_id' => $updateId,
        'message' => [
            'message_id' => $updateId,
            'from' => ['id' => $ticket->participant->telegram_user_id],
            'chat' => ['id' => $ticket->participant->chat_id, 'type' => 'private'],
            'text' => $text,
        ],
    ];
}
