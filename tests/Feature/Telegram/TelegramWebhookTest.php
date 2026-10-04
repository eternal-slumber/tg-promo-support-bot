<?php

use App\Data\TelegramUpdateData;
use App\Enums\DeliveryStatus;
use App\Enums\MessageAuthor;
use App\Enums\TelegramUpdateKind;
use App\Enums\TicketStatus;
use App\Jobs\DeliverTelegramMessage;
use App\Jobs\ProcessIncomingMessage;
use App\Livewire\OperatorDashboard;
use App\Models\Message;
use App\Models\TelegramParticipant;
use App\Models\TelegramUpdate;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TelegramIngestionService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

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

test('queues normal processing on the application database before commit', function () {
    Queue::fake([ProcessIncomingMessage::class]);
    $service = app(TelegramIngestionService::class);

    $service->ingest(new TelegramUpdateData(1002, TelegramUpdateKind::Message, 2002, 3002, 4002, 'Вопрос'));

    Queue::assertPushed(ProcessIncomingMessage::class, fn (ProcessIncomingMessage $job): bool => $job->connection === 'database' && $job->afterCommit === false);
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

test('ignores group messages without changing the private delivery address', function (array $content) {
    Queue::fake();
    Http::preventStrayRequests();
    $participant = TelegramParticipant::factory()->create(['telegram_user_id' => 2003, 'chat_id' => 3003]);
    $ticket = Ticket::factory()->for($participant, 'participant')->create();
    $update = telegramTextUpdate(1014, 2003, -3004, 4014, 'Личный вопрос в группе');
    $update['message']['chat']['type'] = 'group';
    unset($update['message']['text']);
    $update['message'] = array_merge($update['message'], $content);

    $this->postJson(route('telegram.webhook'), $update)
        ->assertOk()
        ->assertJsonPath('status', 'ignored');

    expect($participant->refresh()->chat_id)->toBe(3003);
    expect($ticket->refresh()->status)->toBe(TicketStatus::Open);
    $this->assertDatabaseCount('telegram_participants', 1);
    $this->assertDatabaseCount('telegram_updates', 0);
    $this->assertDatabaseCount('messages', 0);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with([
    'text' => [['text' => 'Личный вопрос в группе']],
    'photo' => [['photo' => [['file_id' => 'private-file']]]],
    'voice' => [['voice' => ['file_id' => 'private-file']]],
]);

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

test('keeps common secret formats out of persistence queue payloads and the provider request', function (string $raw, string $expected, array $secrets, array $redactionTypes) {
    Queue::fake();
    Http::preventStrayRequests();
    config()->set('llm.endpoint', 'https://llm.example/v1/chat/completions');
    Http::fake([
        'https://llm.example/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode([
            'decision' => 'escalate', 'reason' => 'participant_specific', 'answer' => null, 'evidence' => [],
        ], JSON_THROW_ON_ERROR)]]]]),
    ]);
    $this->postJson(route('telegram.webhook'), telegramTextUpdate(1020, 2020, 3020, 4020, $raw))->assertOk();

    $message = Message::query()->where('direction', 'inbound')->sole();
    expect($message->body)->toBe($expected);
    expect($message->redaction_types)->toBe($redactionTypes);
    expect(Message::query()->where('author', MessageAuthor::System)->count())->toBe(1);
    expect(json_encode(TelegramUpdate::query()->sole()->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))
        ->not->toContain(...$secrets);
    Queue::assertPushed(ProcessIncomingMessage::class, fn (ProcessIncomingMessage $job): bool => $job->messageId === $message->id
        && ! str_contains(serialize($job), $raw));
    foreach (Queue::pushedJobs() as $jobs) {
        foreach ($jobs as $queued) {
            expect(serialize($queued['job']))->not->toContain(...$secrets);
        }
    }

    app()->call([(new ProcessIncomingMessage($message->id)), 'handle']);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://llm.example/v1/chat/completions'
        && $request['messages'][1] === ['role' => 'user', 'content' => $expected]);
    expect(json_encode(Message::all()->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))->not->toContain(...$secrets);
})->with([
    'common secret formats' => [
        "Не могу войти. Карта 2200\u{00A0}1234\u{00A0}5678\u{00A0}9012, код из смс: 123 456, пароль qwerty123; password: secret word",
        'Не могу войти. Карта [REDACTED_PAYMENT_CARD], код из смс: [REDACTED_OTP], пароль [REDACTED_PASSWORD]; password: [REDACTED_PASSWORD]',
        ["2200\u{00A0}1234\u{00A0}5678\u{00A0}9012", '123 456', 'qwerty123', 'secret word'],
        ['payment_card', 'otp', 'password'],
    ],
    'card followed by amount' => [
        'Карта 4111 1111 1111 1111 100 рублей',
        'Карта [REDACTED_PAYMENT_CARD] 100 рублей',
        ['4111 1111 1111 1111'],
        ['payment_card'],
    ],
    'owned values without digits' => [
        'Мой пароль qwerty; My password reset; Мой пароль secret.word',
        'Мой пароль [REDACTED_PASSWORD]; My password [REDACTED_PASSWORD]; Мой пароль [REDACTED_PASSWORD]',
        ['qwerty', 'reset', 'secret.word'],
        ['password'],
    ],
    'confirmed secret edge cases' => [
        'Карта 2200123456789012; код из SMS: 123-456; Пароль qwerty123. Почему отклонили чек?',
        'Карта [REDACTED_PAYMENT_CARD]; код из SMS: [REDACTED_OTP]; Пароль [REDACTED_PASSWORD]. Почему отклонили чек?',
        ['2200123456789012', '123-456', 'qwerty123'],
        ['payment_card', 'otp', 'password'],
    ],
]);

test('preserves ordinary password and sms questions in storage and the provider request', function (string $body) {
    Queue::fake();
    Http::preventStrayRequests();
    config()->set('llm.endpoint', 'https://llm.example/v1/chat/completions');
    Http::fake([
        'https://llm.example/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode([
            'decision' => 'escalate', 'reason' => 'participant_specific', 'answer' => null, 'evidence' => [],
        ], JSON_THROW_ON_ERROR)]]]]),
    ]);
    $this->postJson(route('telegram.webhook'), telegramTextUpdate(1021, 2021, 3021, 4021, $body))->assertOk();

    $message = Message::query()->where('direction', 'inbound')->sole();
    expect($message->body)->toBe($body)
        ->and($message->sensitive_data_redacted)->toBeFalse()
        ->and($message->redaction_types)->toBe([]);
    Queue::assertPushed(ProcessIncomingMessage::class, fn (ProcessIncomingMessage $job): bool => $job->messageId === $message->id);

    app()->call([new ProcessIncomingMessage($message->id), 'handle']);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://llm.example/v1/chat/completions'
        && $request['messages'][1] === ['role' => 'user', 'content' => $body]);
    expect($message->refresh()->body)->toBe($body)
        ->and($message->ticket_id)->not->toBeNull()
        ->and(Message::query()->where('author', MessageAuthor::System)->count())->toBe(0);
})->with([
    'Russian questions' => 'Как поменять пароль в личном кабинете? Код из смс пришел вчера.',
    'English password and OTP questions' => 'Password change does not work. SMS code arrives after 10 minutes.',
    'ordinary numeric identifiers' => 'Номер операции 2200123456789012, номер обращения 123-456. Почему отклонили чек?',
]);

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

test('ignores legacy callback updates and their repeats without processing or acknowledging them', function () {
    Queue::fake([ProcessIncomingMessage::class]);
    Http::preventStrayRequests();
    $callback = [
        'update_id' => 1007,
        'callback_query' => [
            'id' => 'callback-id',
            'from' => ['id' => 2007],
            'message' => ['chat' => ['id' => 3007, 'type' => 'private']],
            'data' => 'resolved:1',
        ],
    ];

    $this->postJson(route('telegram.webhook'), $callback)->assertOk()->assertJsonPath('status', 'ignored');
    $this->postJson(route('telegram.webhook'), $callback)->assertOk()->assertJsonPath('status', 'ignored');

    expect(TelegramUpdate::query()->count())->toBe(0)
        ->and(Message::query()->count())->toBe(0);

    Queue::assertNothingPushed();
    Http::assertNothingSent();
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

test('ignores service events and their repeats without storing content or changing a ticket', function (array $content) {
    $participant = TelegramParticipant::factory()->create(['telegram_user_id' => 2015, 'chat_id' => 3015]);
    $ticket = Ticket::factory()->for($participant, 'participant')->resolved()->create();
    Queue::fake();
    Http::preventStrayRequests();
    $payload = [
        'update_id' => 1015,
        'message' => array_merge([
            'message_id' => 4015,
            'chat' => ['id' => 9999, 'type' => 'private'],
            'date' => 1712340000,
        ], $content),
    ];

    $this->postJson(route('telegram.webhook'), $payload)->assertOk()->assertExactJson(['status' => 'ignored']);
    $this->postJson(route('telegram.webhook'), $payload)->assertOk()->assertExactJson(['status' => 'ignored']);

    expect($participant->refresh()->chat_id)->toBe(3015);
    expect($ticket->refresh()->status)->toBe(TicketStatus::Resolved);
    $this->assertDatabaseCount('telegram_participants', 1);
    $this->assertDatabaseCount('tickets', 1);
    $this->assertDatabaseCount('telegram_updates', 0);
    $this->assertDatabaseCount('messages', 0);
    $this->assertDatabaseCount('jobs', 0);
    $this->assertDatabaseCount('failed_jobs', 0);
    $this->assertDatabaseCount('cache', 0);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with([
    'service event without sender' => [['delete_chat_photo' => true]],
    'service event with sender' => [['from' => ['id' => 2015], 'delete_chat_photo' => true]],
]);

test('delivers a deduplicated text-only fallback for private media and reopens resolved tickets', function (array $content, ?TicketStatus $status) {
    config()->set('telegram.bot_token', 'test-bot-token');
    config()->set('telegram.api_base_url', 'https://telegram.example');
    $participant = TelegramParticipant::factory()->create(['telegram_user_id' => 2015, 'chat_id' => 3015]);
    $ticket = null;
    if ($status !== null) {
        $factory = Ticket::factory()->for($participant, 'participant');
        $ticket = ($status === TicketStatus::Resolved ? $factory->resolved() : $factory)->create();
    }
    Queue::fake();
    Http::preventStrayRequests();
    Http::fake(['https://telegram.example/bottest-bot-token/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 501]])]);
    $payload = [
        'update_id' => 1015,
        'message' => array_merge([
            'message_id' => 4015,
            'from' => ['id' => 2015],
            'chat' => ['id' => 3015, 'type' => 'private'],
        ], $content),
    ];

    $this->postJson(route('telegram.webhook'), $payload)->assertOk()->assertExactJson(['status' => 'accepted']);
    $this->postJson(route('telegram.webhook'), $payload)->assertOk()->assertExactJson(['status' => 'duplicate']);
    $outbound = Message::query()->sole();
    app()->call([new DeliverTelegramMessage($outbound->id), 'handle']);

    expect($outbound->refresh()->author)->toBe(MessageAuthor::System);
    expect($outbound->body)->toContain('только текстовые сообщения', 'вопрос', 'текст подписи', 'отдельным текстовым сообщением');
    expect($outbound->delivery_status)->toBe(DeliveryStatus::Sent);
    expect($outbound->ticket_id)->toBe($ticket?->id);
    expect($ticket?->refresh()->status)->toBe($status === null ? null : TicketStatus::Open);
    expect($ticket?->resolved_since)->toBeNull();
    $this->assertDatabaseCount('telegram_participants', 1);
    $this->assertDatabaseCount('telegram_updates', 1);
    $this->assertDatabaseCount('tickets', $ticket === null ? 0 : 1);
    $this->assertDatabaseCount('messages', 1);
    $this->assertDatabaseCount('support_decisions', 0);
    expect(TelegramUpdate::query()->sole()->kind)->toBe('non_text_message');
    expect(json_encode([Message::all()->toArray(), TelegramUpdate::all()->toArray(), Queue::pushedJobs()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))
        ->not->toContain('qwerty123', 'Почему отклонили чек?', 'Проверьте мой чек', 'private-file');
    Queue::assertNotPushed(ProcessIncomingMessage::class);
    Queue::assertPushed(DeliverTelegramMessage::class, 1);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://telegram.example/bottest-bot-token/sendMessage'
        && $request['chat_id'] === 3015 && $request['text'] === $outbound->body);
    Http::assertSentCount(1);
})->with([
    'photo without a caption or ticket' => [['photo' => [['file_id' => 'private-file']]], null],
    'photo with a meaningful caption without a ticket' => [
        ['photo' => [['file_id' => 'private-file']], 'caption' => 'Проверьте мой чек'], null,
    ],
    'photo with meaningful and sensitive caption on a waiting ticket' => [
        ['photo' => [['file_id' => 'private-file']], 'caption' => 'Почему отклонили чек? пароль qwerty123'], TicketStatus::Resolved,
    ],
    'document without a ticket' => [['document' => ['file_id' => 'private-file', 'file_name' => 'qwerty123.txt']], null],
    'voice on an open ticket' => [['voice' => ['file_id' => 'private-file']], TicketStatus::Open],
    'sticker without a ticket' => [['sticker' => ['file_id' => 'private-file']], null],
    'video without a ticket' => [['video' => ['file_id' => 'private-file']], null],
    'contact without a ticket' => [['contact' => ['phone_number' => '+7 999 123-45-67', 'first_name' => 'qwerty123']], null],
]);

test('limits repeated non-text fallback notices and allows another after one minute', function () {
    $this->freezeTime();
    Queue::fake();
    Http::preventStrayRequests();
    $payload = [
        'update_id' => 1017,
        'message' => ['message_id' => 4017, 'from' => ['id' => 2017], 'chat' => ['id' => 3017, 'type' => 'private'], 'photo' => [['file_id' => 'private-file']]],
    ];

    $this->postJson(route('telegram.webhook'), $payload)->assertOk();
    $payload['update_id']++;
    $payload['message']['message_id']++;
    $this->postJson(route('telegram.webhook'), $payload)->assertOk();
    $this->assertDatabaseCount('messages', 1);
    Queue::assertPushed(DeliverTelegramMessage::class, 1);
    $this->travel(61)->seconds();
    $payload['update_id']++;
    $payload['message']['message_id']++;
    $this->postJson(route('telegram.webhook'), $payload)->assertOk();

    $this->assertDatabaseCount('telegram_updates', 3);
    $this->assertDatabaseCount('messages', 2);
    $this->assertDatabaseCount('tickets', 0);
    Queue::assertPushed(DeliverTelegramMessage::class, 2);
    Queue::assertNotPushed(ProcessIncomingMessage::class);
    Http::assertNothingSent();
});

test('accepts a text question after non-text fallback without consuming its AI quota', function () {
    config()->set('llm.requests_per_minute', 1);
    config()->set('llm.requests_per_day', 1);
    Queue::fake();
    Http::preventStrayRequests();
    $payload = telegramTextUpdate(1018, 2018, 3018, 4018, '');
    unset($payload['message']['text']);
    $payload['message']['document'] = ['file_id' => 'private-file'];

    $this->postJson(route('telegram.webhook'), $payload)->assertOk()->assertJsonPath('status', 'accepted');
    $this->postJson(route('telegram.webhook'), telegramTextUpdate(1019, 2018, 3018, 4019, 'Когда следующий розыгрыш?'))
        ->assertOk()->assertJsonPath('status', 'accepted');

    $this->assertDatabaseCount('messages', 2);
    $this->assertDatabaseCount('telegram_updates', 2);
    $this->assertDatabaseCount('tickets', 0);
    Queue::assertPushed(ProcessIncomingMessage::class, 1);
    Queue::assertPushed(DeliverTelegramMessage::class, 1);
    Http::assertNothingSent();
});

test('rejects malformed non-text updates without side effects', function () {
    Queue::fake();
    Http::preventStrayRequests();

    $this->postJson(route('telegram.webhook'), [
        'update_id' => 1016,
        'message' => [
            'from' => ['id' => 2016],
            'chat' => ['id' => 3016, 'type' => 'private'],
            'photo' => [['file_id' => 'photo-file']],
        ],
    ])->assertUnprocessable()->assertExactJson(['status' => 'ignored', 'reason' => 'malformed_update']);

    $this->assertDatabaseCount('telegram_updates', 0);
    $this->assertDatabaseCount('telegram_participants', 0);
    $this->assertDatabaseCount('messages', 0);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

test('delivers the start warning without normal processing or counting it as a bot answer', function () {
    Queue::fake();
    Http::preventStrayRequests();
    config()->set('telegram.bot_token', 'test-bot-token');
    config()->set('telegram.api_base_url', 'https://telegram.example');
    Http::fake(['https://telegram.example/bottest-bot-token/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 501]])]);

    $this->postJson(route('telegram.webhook'), telegramTextUpdate(1009, 2009, 3009, 4009, '/start'))
        ->assertOk();

    $warning = Message::query()->where('direction', 'outbound')->sole();
    app()->call([new DeliverTelegramMessage($warning->id), 'handle']);

    $this->actingAs(User::factory()->create());
    Livewire::test(OperatorDashboard::class)->assertViewHas('statistics', fn (array $statistics): bool => $statistics['bot_resolved'] === 0 && $statistics['bot_prepared'] === 0
    );

    expect($warning->body)->toContain('банковских карт', 'пароли', 'коды из SMS', 'не нужны')
        ->and(Message::query()->count())->toBe(2)
        ->and($warning->refresh()->author)->toBe(MessageAuthor::System)
        ->and($warning->delivery_status)->toBe(DeliveryStatus::Sent);

    Queue::assertNotPushed(ProcessIncomingMessage::class);
    Queue::assertPushed(DeliverTelegramMessage::class, 1);
    Http::assertSentCount(1);
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

test('attaches a message to a waiting ticket and reopens it without queuing normal processing', function () {
    Queue::fake([ProcessIncomingMessage::class]);
    Http::preventStrayRequests();
    $participant = TelegramParticipant::factory()->create(['telegram_user_id' => 2011, 'chat_id' => 3011]);
    $ticket = Ticket::factory()->for($participant, 'participant')->resolved()->create();

    $this->postJson(route('telegram.webhook'), telegramTextUpdate(1011, 2011, 3011, 4011, 'Проблема не решена'))
        ->assertOk();

    expect(Message::query()->sole()->ticket_id)->toBe($ticket->id);

    expect($ticket->refresh()->status)->toBe(TicketStatus::Open)
        ->and($ticket->resolved_since)->toBeNull();

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
            'chat' => ['id' => $chatId, 'type' => 'private'],
            'text' => $text,
        ],
    ];
}
