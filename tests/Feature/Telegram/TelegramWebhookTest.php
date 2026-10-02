<?php

use App\Data\TelegramUpdateData;
use App\Enums\MessageAuthor;
use App\Enums\TelegramUpdateKind;
use App\Enums\TicketStatus;
use App\Jobs\DeliverTelegramMessage;
use App\Jobs\ProcessIncomingMessage;
use App\Models\Message;
use App\Models\TelegramParticipant;
use App\Models\TelegramUpdate;
use App\Models\Ticket;
use App\Services\TelegramIngestionService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    config()->set('telegram.webhook_secret', 'test-webhook-secret');
    $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-webhook-secret');
});

test('rejects unauthenticated updates before any side effect', function (?string $secret) {
    Queue::fake();
    Http::preventStrayRequests();
    $this->flushHeaders();

    if ($secret !== null) {
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $secret);
    }

    $this->postJson(route('telegram.webhook'), telegramTextUpdate(9001, 9002, 9003, 9004, 'Вопрос'))->assertForbidden();

    expect(TelegramUpdate::query()->count())->toBe(0)
        ->and(TelegramParticipant::query()->count())->toBe(0)
        ->and(Message::query()->count())->toBe(0)
        ->and(Ticket::query()->count())->toBe(0);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with(['missing' => [null], 'wrong' => ['wrong-secret']]);

test('fails closed when webhook secret is not configured', function () {
    config()->set('telegram.webhook_secret', null);
    Queue::fake();

    $this->postJson(route('telegram.webhook'), ['update_id' => 1])->assertForbidden();

    expect(TelegramUpdate::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('persists a valid text update and queues only its message id', function () {
    Queue::fake();
    Http::preventStrayRequests();

    $this->postJson(route('telegram.webhook'), telegramTextUpdate(1001, 2001, 3001, 4001, 'Когда будут результаты?'))
        ->assertOk()
        ->assertJsonPath('status', 'accepted');

    $participant = TelegramParticipant::query()->sole();
    $update = TelegramUpdate::query()->sole();
    $message = Message::query()->sole();

    expect($participant->telegram_user_id)->toBe(2001)
        ->and($participant->chat_id)->toBe(3001)
        ->and($update->kind)->toBe(TelegramUpdateKind::Message->value)
        ->and($update->participant_id)->toBe($participant->id)
        ->and($message->telegram_update_id)->toBe($update->id)
        ->and($message->telegram_message_id)->toBe(4001)
        ->and($message->body)->toBe('Когда будут результаты?')
        ->and($message->ticket_id)->toBeNull();

    Queue::assertPushed(ProcessIncomingMessage::class, fn (ProcessIncomingMessage $job): bool => $job->messageId === $message->id);
});

test('marks normal processing for dispatch after commit', function () {
    Queue::fake([ProcessIncomingMessage::class]);
    $service = app(TelegramIngestionService::class);

    $service->ingest(new TelegramUpdateData(1002, TelegramUpdateKind::Message, 2002, 3002, 4002, 'Вопрос'));

    Queue::assertPushed(ProcessIncomingMessage::class, fn (ProcessIncomingMessage $job): bool => $job->afterCommit === true);
});

test('reuses an existing participant and updates its chat id', function () {
    Queue::fake([ProcessIncomingMessage::class]);
    Http::preventStrayRequests();

    $this->postJson(route('telegram.webhook'), telegramTextUpdate(1003, 2003, 3003, 4003, 'Первый вопрос'))->assertOk();
    $this->postJson(route('telegram.webhook'), telegramTextUpdate(1004, 2003, 3004, 4004, 'Второй вопрос'))->assertOk();

    expect(TelegramParticipant::query()->count())->toBe(1)
        ->and(TelegramParticipant::query()->sole()->chat_id)->toBe(3004)
        ->and(Message::query()->count())->toBe(2);

    Queue::assertPushed(ProcessIncomingMessage::class, 2);
});

test('redacts sensitive text before it reaches persistence or the queued job', function () {
    Queue::fake();
    Http::preventStrayRequests();
    $card = '2200 1234 5678 9012';

    $this->postJson(route('telegram.webhook'), telegramTextUpdate(1005, 2005, 3005, 4005, "переведите на карту {$card}"))
        ->assertOk();

    $inbound = Message::query()->where('direction', 'inbound')->sole();
    $warning = Message::query()->where('author', MessageAuthor::System)->sole();

    expect($inbound->body)->toBe('переведите на карту [REDACTED_PAYMENT_CARD]')
        ->not->toContain($card)
        ->and($inbound->sensitive_data_redacted)->toBeTrue()
        ->and($inbound->redaction_types)->toBe(['payment_card'])
        ->and($warning->body)->not->toContain($card)
        ->and(Schema::getColumnListing('telegram_updates'))->not->toContain('payload', 'raw_payload');

    Queue::assertPushed(ProcessIncomingMessage::class, function (ProcessIncomingMessage $job) use ($card, $inbound): bool {
        return $job->messageId === $inbound->id
            && ! str_contains(serialize($job), $card);
    });
});

test('creates one redaction notification for multiple hidden values', function () {
    Queue::fake();
    Http::preventStrayRequests();

    $this->postJson(
        route('telegram.webhook'),
        telegramTextUpdate(1013, 2013, 3013, 4013, 'Карта 2200 1234 5678 9012, код из смс 123456, пароль: qwerty123'),
    )->assertOk();

    $inbound = Message::query()->where('direction', 'inbound')->sole();

    expect($inbound->redaction_types)->toBe(['payment_card', 'otp', 'password'])
        ->and(Message::query()->where('author', MessageAuthor::System)->count())->toBe(1);
});

test('returns success without side effects for a duplicate update id', function () {
    Queue::fake([ProcessIncomingMessage::class]);
    Http::preventStrayRequests();
    $update = telegramTextUpdate(1006, 2006, 3006, 4006, 'Один вопрос');

    $this->postJson(route('telegram.webhook'), $update)->assertOk()->assertJsonPath('status', 'accepted');
    $this->postJson(route('telegram.webhook'), $update)->assertOk()->assertJsonPath('status', 'duplicate');

    expect(TelegramUpdate::query()->count())->toBe(1)
        ->and(TelegramParticipant::query()->count())->toBe(1)
        ->and(Message::query()->count())->toBe(1)
        ->and(Ticket::query()->count())->toBe(0);

    Queue::assertPushed(ProcessIncomingMessage::class, 1);
});

test('acknowledges callback updates while processing duplicate delivery idempotently', function () {
    Queue::fake([ProcessIncomingMessage::class]);
    Http::preventStrayRequests();
    Http::fake(['*answerCallbackQuery' => Http::response(['ok' => true, 'result' => true])]);
    $callback = [
        'update_id' => 1007,
        'callback_query' => [
            'id' => 'callback-id',
            'from' => ['id' => 2007],
            'message' => ['chat' => ['id' => 3007]],
            'data' => 'resolved:1',
        ],
    ];

    $this->postJson(route('telegram.webhook'), $callback)->assertOk()->assertJsonPath('status', 'accepted');
    $this->postJson(route('telegram.webhook'), $callback)->assertOk()->assertJsonPath('status', 'duplicate');

    expect(TelegramUpdate::query()->count())->toBe(1)
        ->and(TelegramUpdate::query()->sole()->kind)->toBe(TelegramUpdateKind::CallbackQuery->value)
        ->and(Message::query()->count())->toBe(0);

    Queue::assertNothingPushed();
    Http::assertSentCount(2);
});

test('returns predictable responses for malformed and unsupported updates', function () {
    Queue::fake([ProcessIncomingMessage::class]);
    Http::preventStrayRequests();

    $this->postJson(route('telegram.webhook'), ['message' => []])
        ->assertUnprocessable()
        ->assertJsonPath('reason', 'malformed_update');
    $this->postJson(route('telegram.webhook'), ['update_id' => 1008, 'inline_query' => []])
        ->assertOk()
        ->assertJsonPath('status', 'ignored');

    expect(TelegramUpdate::query()->count())->toBe(0)
        ->and(Message::query()->count())->toBe(0);

    Queue::assertNothingPushed();
});

test('does not queue normal processing for the start command', function () {
    Queue::fake();
    Http::preventStrayRequests();

    $this->postJson(route('telegram.webhook'), telegramTextUpdate(1009, 2009, 3009, 4009, '/start'))
        ->assertOk();

    $warning = Message::query()->where('author', MessageAuthor::Bot)->sole();

    expect($warning->body)->toContain('банковских карт', 'пароли', 'коды из SMS', 'не нужны')
        ->and(Message::query()->count())->toBe(2);

    Queue::assertNotPushed(ProcessIncomingMessage::class);
    Queue::assertPushed(DeliverTelegramMessage::class, 1);
});

test('attaches a message to an open ticket without queuing normal processing', function () {
    Queue::fake([ProcessIncomingMessage::class]);
    Http::preventStrayRequests();
    $participant = TelegramParticipant::factory()->create(['telegram_user_id' => 2010, 'chat_id' => 3010]);
    $ticket = Ticket::factory()->for($participant, 'participant')->create(['status' => TicketStatus::Open]);

    $this->postJson(route('telegram.webhook'), telegramTextUpdate(1010, 2010, 3010, 4010, 'Есть уточнение'))
        ->assertOk();

    expect(Message::query()->sole()->ticket_id)->toBe($ticket->id);

    Queue::assertNothingPushed();
});

test('attaches a message to a waiting ticket without queuing normal processing', function () {
    Queue::fake([ProcessIncomingMessage::class]);
    Http::preventStrayRequests();
    $participant = TelegramParticipant::factory()->create(['telegram_user_id' => 2011, 'chat_id' => 3011]);
    $ticket = Ticket::factory()->for($participant, 'participant')->waitingForUser()->create();

    $this->postJson(route('telegram.webhook'), telegramTextUpdate(1011, 2011, 3011, 4011, 'Проблема не решена'))
        ->assertOk();

    expect(Message::query()->sole()->ticket_id)->toBe($ticket->id);

    Queue::assertNothingPushed();
});

test('queues normal processing when the participant has only closed tickets', function () {
    Queue::fake([ProcessIncomingMessage::class]);
    Http::preventStrayRequests();
    $participant = TelegramParticipant::factory()->create(['telegram_user_id' => 2012, 'chat_id' => 3012]);
    Ticket::factory()->for($participant, 'participant')->closed()->create();

    $this->postJson(route('telegram.webhook'), telegramTextUpdate(1012, 2012, 3012, 4012, 'Новый вопрос'))
        ->assertOk();

    $message = Message::query()->sole();

    expect($message->ticket_id)->toBeNull();

    Queue::assertPushed(ProcessIncomingMessage::class, fn (ProcessIncomingMessage $job): bool => $job->messageId === $message->id);
});

/**
 * @return array<string, mixed>
 */
function telegramTextUpdate(int $updateId, int $userId, int $chatId, int $messageId, string $text): array
{
    return [
        'update_id' => $updateId,
        'message' => [
            'message_id' => $messageId,
            'from' => ['id' => $userId],
            'chat' => ['id' => $chatId],
            'text' => $text,
        ],
    ];
}
