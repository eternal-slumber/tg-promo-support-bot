<?php

use App\Enums\MessageAuthor;
use App\Enums\MessageDirection;
use App\Models\Message;
use App\Models\TelegramParticipant;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    config()->set('telegram.webhook_secret', 'test-webhook-secret');
    config()->set('llm.requests_per_minute', 2);
    config()->set('llm.requests_per_day', 10);
    $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-webhook-secret');
});

test('acknowledges excess questions without AI work and sends only one rate limit notice per minute', function () {
    $this->freezeTime();
    Http::preventStrayRequests();

    for ($id = 1; $id <= 6; $id++) {
        $this->postJson(route('telegram.webhook'), aiLimitedTelegramUpdate($id))
            ->assertOk()->assertJsonPath('status', $id <= 2 ? 'accepted' : 'ignored');
    }
    $this->postJson(route('telegram.webhook'), aiLimitedTelegramUpdate(3))
        ->assertOk()->assertJsonPath('status', 'duplicate');

    $this->assertDatabaseCount('telegram_updates', 6);
    expect(Message::query()->where('direction', MessageDirection::Inbound)->count())->toBe(2);
    expect(DB::table('jobs')->where('queue', 'ai')->count())->toBe(2);
    expect(DB::table('jobs')->where('queue', 'telegram')->count())->toBe(1);
    expect(Message::query()->where('author', MessageAuthor::System)->sole()->body)
        ->toBe('Вы отправили слишком много вопросов. Попробуйте позже.');
    Http::assertNothingSent();
});

test('allows a new question after the minute limit expires and isolates participant quotas', function () {
    $this->freezeTime();
    config()->set('llm.requests_per_minute', 1);
    $this->postJson(route('telegram.webhook'), aiLimitedTelegramUpdate(1))->assertOk()->assertJsonPath('status', 'accepted');
    $this->postJson(route('telegram.webhook'), aiLimitedTelegramUpdate(2))->assertOk()->assertJsonPath('status', 'ignored');

    $this->postJson(route('telegram.webhook'), aiLimitedTelegramUpdate(3, 8002))->assertOk()->assertJsonPath('status', 'accepted');
    $this->travel(59)->seconds();
    $this->postJson(route('telegram.webhook'), aiLimitedTelegramUpdate(4))->assertOk()->assertJsonPath('status', 'ignored');
    $this->travel(2)->seconds();
    $this->postJson(route('telegram.webhook'), aiLimitedTelegramUpdate(5))->assertOk()->assertJsonPath('status', 'accepted');

    expect(DB::table('jobs')->where('queue', 'ai')->count())->toBe(3);
    expect(Message::query()->where('author', MessageAuthor::System)->count())->toBe(1);
});

test('the daily quota survives minute resets and resets after 24 hours', function () {
    $this->freezeTime();
    config()->set('llm.requests_per_minute', 1);
    config()->set('llm.requests_per_day', 2);
    $this->postJson(route('telegram.webhook'), aiLimitedTelegramUpdate(1))->assertOk()->assertJsonPath('status', 'accepted');
    $this->travel(61)->seconds();
    $this->postJson(route('telegram.webhook'), aiLimitedTelegramUpdate(2))->assertOk()->assertJsonPath('status', 'accepted');
    $this->travel(61)->seconds();

    $this->postJson(route('telegram.webhook'), aiLimitedTelegramUpdate(3))->assertOk()->assertJsonPath('status', 'ignored');
    $this->travel(1)->days();
    $this->postJson(route('telegram.webhook'), aiLimitedTelegramUpdate(4))->assertOk()->assertJsonPath('status', 'accepted');

    expect(DB::table('jobs')->where('queue', 'ai')->count())->toBe(3);
    expect(Message::query()->where('direction', MessageDirection::Inbound)->count())->toBe(3);
});

test('a duplicate webhook does not consume another AI slot', function () {
    $this->freezeTime();
    $this->postJson(route('telegram.webhook'), aiLimitedTelegramUpdate(1))->assertOk()->assertJsonPath('status', 'accepted');
    $this->postJson(route('telegram.webhook'), aiLimitedTelegramUpdate(1))->assertOk()->assertJsonPath('status', 'duplicate');

    $this->postJson(route('telegram.webhook'), aiLimitedTelegramUpdate(2))->assertOk()->assertJsonPath('status', 'accepted');
    $this->postJson(route('telegram.webhook'), aiLimitedTelegramUpdate(3))->assertOk()->assertJsonPath('status', 'ignored');

    expect(DB::table('jobs')->where('queue', 'ai')->count())->toBe(2);
    $this->assertDatabaseCount('telegram_updates', 3);
});

test('start and messages on an active ticket remain available without consuming AI slots', function () {
    $this->freezeTime();
    config()->set('llm.requests_per_minute', 1);
    $participant = TelegramParticipant::factory()->create(['telegram_user_id' => 8001, 'chat_id' => 8001]);
    $this->postJson(route('telegram.webhook'), aiLimitedTelegramUpdate(1, 8001, '/start'))->assertOk()->assertJsonPath('status', 'accepted');
    $this->postJson(route('telegram.webhook'), aiLimitedTelegramUpdate(2))->assertOk()->assertJsonPath('status', 'accepted');
    $ticket = Ticket::factory()->for($participant, 'participant')->create();

    $this->postJson(route('telegram.webhook'), aiLimitedTelegramUpdate(3, 8001, 'Уточнение оператору'))->assertOk()->assertJsonPath('status', 'accepted');

    expect(Message::query()->where('body', 'Уточнение оператору')->sole()->ticket_id)->toBe($ticket->id);
    expect(DB::table('jobs')->where('queue', 'ai')->count())->toBe(1);
    expect(DB::table('jobs')->where('queue', 'telegram')->count())->toBe(1);
});

test('a failed queue insert rolls back quota consumption and permits the same webhook to retry', function () {
    $this->freezeTime();
    config()->set('llm.requests_per_minute', 1);
    config()->set('cache.stores.database.connection', 'unconfigured-external-cache');
    TelegramParticipant::factory()->create(['telegram_user_id' => 8001, 'chat_id' => 8001]);
    DB::statement('ALTER TABLE jobs ADD CONSTRAINT reject_job_inserts CHECK (false) NOT VALID');

    $this->postJson(route('telegram.webhook'), aiLimitedTelegramUpdate(1))->assertServerError();

    $this->assertDatabaseCount('telegram_updates', 0);
    $this->assertDatabaseCount('messages', 0);
    $this->assertDatabaseCount('jobs', 0);
    $this->assertDatabaseCount('cache', 0);
    DB::statement('ALTER TABLE jobs DROP CONSTRAINT reject_job_inserts');
    $this->postJson(route('telegram.webhook'), aiLimitedTelegramUpdate(1))->assertOk()->assertJsonPath('status', 'accepted');
    expect(DB::table('jobs')->where('queue', 'ai')->count())->toBe(1);
});

test('repeated start and redaction warnings do not flood delivery or consume the AI quota', function () {
    $this->freezeTime();
    config()->set('llm.requests_per_minute', 1);
    $participant = TelegramParticipant::factory()->create(['telegram_user_id' => 8001, 'chat_id' => 8001]);

    for ($id = 1; $id <= 3; $id++) {
        $this->postJson(route('telegram.webhook'), aiLimitedTelegramUpdate($id, 8001, '/start'))->assertOk();
    }
    $this->postJson(route('telegram.webhook'), aiLimitedTelegramUpdate(4))->assertOk()->assertJsonPath('status', 'accepted');
    Ticket::factory()->for($participant, 'participant')->create();
    for ($id = 5; $id <= 7; $id++) {
        $this->postJson(route('telegram.webhook'), aiLimitedTelegramUpdate($id, 8001, 'Карта 4111 1111 1111 1111'))->assertOk();
    }

    expect(DB::table('jobs')->where('queue', 'ai')->count())->toBe(1);
    expect(DB::table('jobs')->where('queue', 'telegram')->count())->toBe(2);
    expect(Message::query()->where('direction', MessageDirection::Inbound)->count())->toBe(7);
    expect(Message::query()->where('author', MessageAuthor::System)->count())->toBe(2);
    $this->travel(61)->seconds();
    $this->postJson(route('telegram.webhook'), aiLimitedTelegramUpdate(8, 8001, '/start'))->assertOk();
    expect(DB::table('jobs')->where('queue', 'telegram')->count())->toBe(3);
});

test('a failed rate limit notice enqueue can be retried without losing the notice', function () {
    $this->freezeTime();
    config()->set('llm.requests_per_minute', 1);
    $this->postJson(route('telegram.webhook'), aiLimitedTelegramUpdate(1))->assertOk();
    $cacheCount = DB::table('cache')->count();
    DB::statement('ALTER TABLE jobs ADD CONSTRAINT reject_job_inserts CHECK (false) NOT VALID');

    $this->postJson(route('telegram.webhook'), aiLimitedTelegramUpdate(2))->assertServerError();

    $this->assertDatabaseCount('telegram_updates', 1);
    $this->assertDatabaseCount('messages', 1);
    expect(DB::table('cache')->count())->toBe($cacheCount);
    DB::statement('ALTER TABLE jobs DROP CONSTRAINT reject_job_inserts');
    $this->postJson(route('telegram.webhook'), aiLimitedTelegramUpdate(2))->assertOk()->assertJsonPath('status', 'ignored');
    expect(DB::table('jobs')->where('queue', 'telegram')->count())->toBe(1);
});

function aiLimitedTelegramUpdate(int $id, int $userId = 8001, string $body = 'Когда будут результаты?'): array
{
    return [
        'update_id' => $id,
        'message' => [
            'message_id' => $id,
            'from' => ['id' => $userId],
            'chat' => ['id' => $userId, 'type' => 'private'],
            'text' => $body,
        ],
    ];
}
