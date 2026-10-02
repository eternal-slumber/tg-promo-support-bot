<?php

use App\Enums\DeliveryStatus;
use App\Enums\MessageAuthor;
use App\Enums\TicketCloseReason;
use App\Enums\TicketStatus;
use App\Jobs\DeliverTelegramMessage;
use App\Jobs\ProcessIncomingMessage;
use App\Models\Message;
use App\Models\TelegramParticipant;
use App\Models\TelegramUpdate;
use App\Models\Ticket;
use App\Services\TelegramBotClient;
use App\Services\TicketLifecycleService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\MockInterface;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    config()->set('telegram.webhook_secret', 'test-webhook-secret');
    $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-webhook-secret');
});

test('rejects spoofed owner callbacks without a valid webhook secret', function (?string $secret) {
    Queue::fake();
    Http::preventStrayRequests();
    $owner = TelegramParticipant::factory()->create(['telegram_user_id' => 9001]);
    $ticket = Ticket::factory()->for($owner, 'participant')->waitingForUser()->create();
    $this->flushHeaders();

    if ($secret !== null) {
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $secret);
    }

    $this->postJson(route('telegram.webhook'), telegramCallbackUpdate(9002, 'spoof', 9001, $owner->chat_id, "resolved:{$ticket->id}"))->assertForbidden();

    expect($ticket->refresh()->status)->toBe(TicketStatus::WaitingForUser)
        ->and($ticket->closed_at)->toBeNull()
        ->and(TelegramUpdate::query()->count())->toBe(0)
        ->and(TelegramParticipant::query()->count())->toBe(1)
        ->and(Ticket::query()->count())->toBe(1)
        ->and(Message::query()->count())->toBe(0);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with(['missing' => [null], 'wrong' => ['wrong-secret']]);

test('an owner can confirm resolution and the callback is acknowledged', function () {
    Queue::fake();
    $participant = TelegramParticipant::factory()->create(['telegram_user_id' => 1001, 'chat_id' => 2001]);
    $ticket = Ticket::factory()->for($participant, 'participant')->waitingForUser()->create();
    $telegram = callbackClient();
    $telegram->shouldReceive('acknowledgeCallback')->once()->with('callback-1');

    $this->postJson(route('telegram.webhook'), telegramCallbackUpdate(3001, 'callback-1', 1001, 2001, "resolved:{$ticket->id}"))
        ->assertOk()
        ->assertJsonPath('status', 'accepted');

    expect($ticket->refresh()->status)->toBe(TicketStatus::Closed)
        ->and($ticket->close_reason)->toBe(TicketCloseReason::UserConfirmed)
        ->and(Message::query()->count())->toBe(0);
});

test('an owner can reopen a ticket and follow-up bypasses normal LLM routing', function () {
    Queue::fake();
    $participant = TelegramParticipant::factory()->create(['telegram_user_id' => 1002, 'chat_id' => 2002]);
    $ticket = Ticket::factory()->for($participant, 'participant')->waitingForUser()->create();
    $telegram = callbackClient();
    $telegram->shouldReceive('acknowledgeCallback')->once()->with('callback-2');

    $this->postJson(route('telegram.webhook'), telegramCallbackUpdate(3002, 'callback-2', 1002, 2002, "unresolved:{$ticket->id}"))
        ->assertOk();

    $notice = Message::query()->sole();

    expect($ticket->refresh()->status)->toBe(TicketStatus::Open)
        ->and($notice->ticket_id)->toBe($ticket->id)
        ->and($notice->author)->toBe(MessageAuthor::Bot)
        ->and($notice->delivery_status)->toBe(DeliveryStatus::Pending);

    Queue::assertPushed(DeliverTelegramMessage::class, fn (DeliverTelegramMessage $job): bool => $job->messageId === $notice->id && $job->afterCommit === true);

    $this->postJson(route('telegram.webhook'), telegramTextCallbackFollowUp(3003, 1002, 2002, 'Проблема всё ещё не решена'))
        ->assertOk();

    expect(Message::query()->where('direction', 'inbound')->sole()->ticket_id)->toBe($ticket->id);
    Queue::assertNotPushed(ProcessIncomingMessage::class);
});

test('a callback from another participant cannot change a ticket', function () {
    Queue::fake();
    $owner = TelegramParticipant::factory()->create(['telegram_user_id' => 1003, 'chat_id' => 2003]);
    $ticket = Ticket::factory()->for($owner, 'participant')->waitingForUser()->create();
    $telegram = callbackClient();
    $telegram->shouldReceive('acknowledgeCallback')->once()->with('callback-3');

    $this->postJson(route('telegram.webhook'), telegramCallbackUpdate(3004, 'callback-3', 1004, 2004, "resolved:{$ticket->id}"))
        ->assertOk()
        ->assertJsonPath('status', 'accepted');

    expect($ticket->refresh()->status)->toBe(TicketStatus::WaitingForUser)
        ->and(Message::query()->count())->toBe(0);
});

test('a duplicate callback is acknowledged without repeating ticket side effects', function () {
    Queue::fake();
    $participant = TelegramParticipant::factory()->create(['telegram_user_id' => 1005, 'chat_id' => 2005]);
    $ticket = Ticket::factory()->for($participant, 'participant')->waitingForUser()->create();
    $telegram = callbackClient();
    $telegram->shouldReceive('acknowledgeCallback')->twice()->with('callback-4');
    $update = telegramCallbackUpdate(3005, 'callback-4', 1005, 2005, "unresolved:{$ticket->id}");

    $this->postJson(route('telegram.webhook'), $update)->assertOk()->assertJsonPath('status', 'accepted');
    $this->postJson(route('telegram.webhook'), $update)->assertOk()->assertJsonPath('status', 'duplicate');

    expect($ticket->refresh()->status)->toBe(TicketStatus::Open)
        ->and(TelegramUpdate::query()->count())->toBe(1)
        ->and(Message::query()->count())->toBe(1);
    Queue::assertPushed(DeliverTelegramMessage::class, 1);
});

test('a callback cannot override an operator manual close', function () {
    Queue::fake();
    $participant = TelegramParticipant::factory()->create(['telegram_user_id' => 1006, 'chat_id' => 2006]);
    $ticket = Ticket::factory()->for($participant, 'participant')->waitingForUser()->create();
    app(TicketLifecycleService::class)->closeManually($ticket);
    $telegram = callbackClient();
    $telegram->shouldReceive('acknowledgeCallback')->once()->with('callback-5');

    $this->postJson(route('telegram.webhook'), telegramCallbackUpdate(3006, 'callback-5', 1006, 2006, "resolved:{$ticket->id}"))
        ->assertOk();

    expect($ticket->refresh()->status)->toBe(TicketStatus::Closed)
        ->and($ticket->close_reason)->toBe(TicketCloseReason::OperatorClosed);
});

function callbackClient(): MockInterface
{
    $client = Mockery::mock(TelegramBotClient::class);
    app()->instance(TelegramBotClient::class, $client);

    return $client;
}

/**
 * @return array<string, mixed>
 */
function telegramCallbackUpdate(int $updateId, string $callbackId, int $userId, int $chatId, string $data): array
{
    return [
        'update_id' => $updateId,
        'callback_query' => [
            'id' => $callbackId,
            'from' => ['id' => $userId],
            'message' => ['chat' => ['id' => $chatId]],
            'data' => $data,
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function telegramTextCallbackFollowUp(int $updateId, int $userId, int $chatId, string $text): array
{
    return [
        'update_id' => $updateId,
        'message' => [
            'message_id' => $updateId,
            'from' => ['id' => $userId],
            'chat' => ['id' => $chatId],
            'text' => $text,
        ],
    ];
}
