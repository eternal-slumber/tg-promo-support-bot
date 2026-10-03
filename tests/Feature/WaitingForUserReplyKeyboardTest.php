<?php

use App\Enums\DeliveryStatus;
use App\Enums\MessageDirection;
use App\Enums\TicketCloseReason;
use App\Enums\TicketStatus;
use App\Jobs\AutoCloseTicket;
use App\Jobs\DeliverTelegramMessage;
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

test('waiting feedback stays in the same ticket without AI or new buttons and invalidates auto close', function (string $text, TicketStatus $status, ?TicketCloseReason $reason) {
    $this->freezeTime();
    $ticket = Ticket::factory()->waitingForUser()->create();
    $waitingSince = $ticket->waiting_since->toISOString();
    Queue::fake();
    Http::preventStrayRequests();
    $payload = waitingFeedbackUpdate($ticket, 11001, $text);

    $this->postJson(route('telegram.webhook'), $payload)->assertOk()->assertJsonPath('status', 'accepted');
    $this->postJson(route('telegram.webhook'), $payload)->assertOk()->assertJsonPath('status', 'duplicate');

    $inbound = Message::query()->sole();
    expect($inbound->ticket_id)->toBe($ticket->id)
        ->and($inbound->body)->toBe($text)
        ->and($inbound->direction)->toBe(MessageDirection::Inbound)
        ->and($ticket->refresh()->status)->toBe($status)
        ->and($ticket->close_reason)->toBe($reason)
        ->and($ticket->waiting_since)->toBeNull();
    $this->assertDatabaseCount('tickets', 1);
    $this->assertDatabaseCount('telegram_updates', 1);
    $this->travel(25)->hours();
    (new AutoCloseTicket($ticket->id, $waitingSince))->handle(app(TicketLifecycleService::class));
    expect($ticket->refresh()->status)->toBe($status)
        ->and($ticket->close_reason)->toBe($reason);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with([
    'confirmed' => ['Проблема решена', TicketStatus::Closed, TicketCloseReason::UserConfirmed],
    'unresolved button' => ['Не решило', TicketStatus::Open, null],
    'implicit unresolved' => ['Не помогло, ошибка осталась', TicketStatus::Open, null],
    'other question' => ['А где посмотреть номер?', TicketStatus::Open, null],
    'command is also feedback' => ['/start', TicketStatus::Open, null],
]);

test('implicit feedback permits the next operator reply and the old timer cannot close its new cycle', function () {
    $this->freezeTime();
    $ticket = Ticket::factory()->create();
    $operator = User::factory()->create();
    Queue::fake();
    Http::preventStrayRequests();
    Http::fake(['*sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 12001]])]);
    $replies = app(OperatorReplyService::class);
    $replyA = $replies->create($operator, $ticket, 'Ответ A');
    app()->call([new DeliverTelegramMessage($replyA->id), 'handle']);
    $oldTimer = Queue::pushed(AutoCloseTicket::class)->sole();

    $this->postJson(route('telegram.webhook'), waitingFeedbackUpdate($ticket, 11002, 'Не помогло'))->assertOk();
    expect($ticket->refresh()->status)->toBe(TicketStatus::Open);
    $replyB = $replies->create($operator, $ticket, 'Ответ B');
    app()->call([new DeliverTelegramMessage($replyB->id), 'handle']);
    $currentTimer = Queue::pushed(AutoCloseTicket::class)->last();
    expect($replyB->refresh()->delivery_status)->toBe(DeliveryStatus::Sent);
    $this->travel(24)->hours();
    $oldTimer->handle(app(TicketLifecycleService::class));
    expect($ticket->refresh()->status)->toBe(TicketStatus::WaitingForUser);
    $currentTimer->handle(app(TicketLifecycleService::class));
    expect($ticket->refresh()->status)->toBe(TicketStatus::Closed)
        ->and($ticket->close_reason)->toBe(TicketCloseReason::AutoClosed);
    Queue::assertPushed(AutoCloseTicket::class, 2);
    Http::assertSentCount(2);
});

test('an old keyboard response after closure does not start AI processing', function () {
    $ticket = Ticket::factory()->closed()->create();
    Queue::fake();
    Http::preventStrayRequests();

    $this->postJson(route('telegram.webhook'), waitingFeedbackUpdate($ticket, 11003, 'Проблема решена'))->assertOk()->assertJsonPath('status', 'ignored');

    expect($ticket->refresh()->status)->toBe(TicketStatus::Closed);
    $this->assertDatabaseCount('messages', 0);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

test('text sent before delivery starts cannot confirm an undelivered reply', function () {
    $ticket = Ticket::factory()->create();
    Queue::fake();
    Http::preventStrayRequests();
    Http::fake(['*sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 12002]])]);
    $reply = app(OperatorReplyService::class)->create(User::factory()->create(), $ticket, 'Ответ');

    $this->postJson(route('telegram.webhook'), waitingFeedbackUpdate($ticket, 11004, 'Проблема решена'))->assertOk();
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

test('reply keyboard text can only affect the senders own waiting ticket', function () {
    $ownerTicket = Ticket::factory()->waitingForUser()->create();
    $senderTicket = Ticket::factory()->waitingForUser()->create();
    Queue::fake();
    Http::preventStrayRequests();
    $payload = waitingFeedbackUpdate($senderTicket, 11005, 'Проблема решена');
    $payload['message']['ticket_id'] = $ownerTicket->id;

    $this->postJson(route('telegram.webhook'), $payload)->assertOk();

    expect($ownerTicket->refresh()->status)->toBe(TicketStatus::WaitingForUser)
        ->and($senderTicket->refresh()->status)->toBe(TicketStatus::Closed)
        ->and($senderTicket->close_reason)->toBe(TicketCloseReason::UserConfirmed)
        ->and(Message::query()->sole()->ticket_id)->toBe($senderTicket->id);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

/** @return array<string, mixed> */
function waitingFeedbackUpdate(Ticket $ticket, int $updateId, string $text): array
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
