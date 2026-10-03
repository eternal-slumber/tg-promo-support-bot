<?php

use App\Enums\DeliveryStatus;
use App\Enums\MessageAuthor;
use App\Enums\SupportDecisionType;
use App\Jobs\DeliverTelegramMessage;
use App\Jobs\ProcessIncomingMessage;
use App\Models\Message;
use App\Models\SupportDecision;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    config()->set('queue.default', 'database');
});

test('delivers the validated concrete answer with one LLM request with trusted Moscow time instead of replacing it with rules', function (string $question, string $answer, array $evidence) {
    $this->travelTo(new DateTimeImmutable('2026-10-05T21:05:00+00:00'));
    config()->set('telegram.webhook_secret', 'test-webhook-secret');
    config()->set('telegram.bot_token', 'test-bot-token');
    config()->set('telegram.api_base_url', 'https://telegram.example');
    config()->set('llm.endpoint', 'https://llm.example/grounded-answer');
    Queue::fake();
    Http::preventStrayRequests();
    $candidate = ['decision' => 'answer', 'reason' => 'rule_answer', 'answer' => $answer, 'evidence' => $evidence];
    Http::fake([
        'https://llm.example/grounded-answer' => Http::response(['choices' => [['message' => ['content' => json_encode($candidate, JSON_THROW_ON_ERROR)]]]]),
        'https://telegram.example/bottest-bot-token/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 501]]),
    ]);

    $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-webhook-secret')->postJson(route('telegram.webhook'), [
        'update_id' => 1001,
        'message' => ['message_id' => 1, 'from' => ['id' => 7001], 'chat' => ['id' => 7001, 'type' => 'private'], 'text' => $question],
    ])->assertOk()->assertJsonPath('status', 'accepted');
    app()->call([new ProcessIncomingMessage(Message::query()->sole()->id), 'handle']);
    $outbound = Message::query()->where('author', MessageAuthor::Bot)->sole();
    app()->call([new DeliverTelegramMessage($outbound->id), 'handle']);

    expect($outbound->refresh()->body)->toBe($answer);
    expect($outbound->delivery_status)->toBe(DeliveryStatus::Sent);
    expect(SupportDecision::query()->sole()->answer_text)->toBe($answer);
    $this->assertDatabaseCount('tickets', 0);
    $this->assertDatabaseCount('messages', 2);
    Http::assertSentInOrder([
        fn (Request $request): bool => $request->url() === 'https://llm.example/grounded-answer'
            && $request['messages'][1]['content'] === $question
            && str_contains($request['messages'][0]['content'], '2026-10-06T00:05:00+03:00')
            && ! str_contains($request['messages'][0]['content'], $question),
        fn (Request $request): bool => $request->url() === 'https://telegram.example/bottest-bot-token/sendMessage'
            && $request['text'] === $answer,
    ]);
    Http::assertSentCount(2);
    Queue::assertPushed(ProcessIncomingMessage::class, 1);
    Queue::assertPushed(DeliverTelegramMessage::class, 1);
})->with([
    'chance arithmetic and excluded products' => [
        'В чеке 7 питьевых йогуртов и 2 творожка, сколько у меня будет шансов?',
        'У вас будет 3 шанса: 7 йогуртов дают 3 полные пары, а творожки в акции не участвуют.',
        [
            ['rule_id' => '5.5', 'quote' => 'Каждые 2 (две) единицы участвующей продукции в одном чеке дают 1 (один) шанс в розыгрышах.'],
            ['rule_id' => '4.1', 'quote' => 'питьевые йогурты, 270 г, все вкусы'],
            ['rule_id' => '4.2', 'quote' => 'в том числе творожки'],
        ],
    ],
    'next drawing after Moscow midnight' => [
        'Когда следующий еженедельный розыгрыш?',
        'Следующий еженедельный розыгрыш состоится сегодня, 6 октября 2026 года, в 15:00 по московскому времени.',
        [['rule_id' => '2.4', 'quote' => 'Еженедельные розыгрыши проводятся каждый вторник в 15:00, с 8 сентября по 3 ноября 2026 г.']],
    ],
]);

test('never sends an invalid result and escalates once without another provider attempt', function (array $changes) {
    config()->set('llm.endpoint', 'https://llm.example/rejected-answer');
    config()->set('llm.max_attempts', 99);
    config()->set('llm.retry_backoff', [0]);
    Http::preventStrayRequests();
    $candidate = array_replace([
        'decision' => 'answer', 'reason' => 'rule_answer', 'answer' => 'Вы выиграли автомобиль',
        'evidence' => [['rule_id' => '7.4', 'quote' => 'Выплата денежного эквивалента призов и замена призов другими не производятся.']],
    ], $changes);
    Http::fake(['https://llm.example/rejected-answer' => Http::response(['choices' => [['message' => ['content' => json_encode($candidate, JSON_THROW_ON_ERROR)]]]])]);
    $inbound = Message::factory()->create(['body' => 'Как получить приз?']);
    ProcessIncomingMessage::dispatch($inbound->id);

    $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'ai', '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 99])->assertSuccessful();
    app()->call([new ProcessIncomingMessage($inbound->id), 'handle']);

    $this->assertDatabaseCount('support_decisions', 1);
    $this->assertDatabaseCount('tickets', 1);
    $this->assertDatabaseCount('messages', 2);
    expect(SupportDecision::query()->sole()->reason)->toBe('llm_failure');
    expect(SupportDecision::query()->sole()->answer_text)->toBeNull();
    expect($inbound->refresh()->ticket_id)->toBe(Ticket::query()->sole()->id);
    expect(Message::query()->where('author', MessageAuthor::Bot)->sole()->body)->toContain('передан оператору')->not->toContain('автомобиль');
    expect(DB::table('jobs')->where('queue', 'telegram')->count())->toBe(1);
    Http::assertSentCount(1);
})->with([
    'unknown decision' => [['decision' => 'unknown']],
    'contradictory reason' => [['reason' => 'participant_specific']],
    'missing evidence' => [['evidence' => []]],
    'unknown rule' => [['evidence' => [['rule_id' => '999.42', 'quote' => 'Вы выиграли автомобиль']]]],
    'fabricated quote' => [['evidence' => [['rule_id' => '7.4', 'quote' => 'Вы выиграли автомобиль']]]],
]);

test('retries only the main request and applies the answer on the third attempt even when larger limits are configured', function () {
    config()->set('llm.endpoint', 'https://llm.example/retry-answer');
    config()->set('llm.max_attempts', 99);
    config()->set('llm.retry_backoff', [0]);
    Http::preventStrayRequests();
    Http::fake(['https://llm.example/retry-answer' => Http::sequence()->push([], 503)->push([], 429)->push([
        'choices' => [['message' => ['content' => json_encode([
            'decision' => 'answer', 'reason' => 'rule_answer', 'answer' => 'Деньгами заменить приз нельзя.',
            'evidence' => [['rule_id' => '7.4', 'quote' => 'Выплата денежного эквивалента призов и замена призов другими не производятся.']],
        ], JSON_THROW_ON_ERROR)]]],
    ])]);
    $inbound = Message::factory()->create(['body' => 'Можно заменить приз деньгами?']);
    ProcessIncomingMessage::dispatch($inbound->id);

    $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'ai', '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 99])->assertSuccessful();
    app()->call([new ProcessIncomingMessage($inbound->id), 'handle']);

    expect(SupportDecision::query()->sole()->type)->toBe(SupportDecisionType::Answer);
    expect(Message::query()->where('author', MessageAuthor::Bot)->sole()->body)->toBe('Деньгами заменить приз нельзя.');
    $this->assertDatabaseCount('tickets', 0);
    $this->assertDatabaseCount('support_decisions', 1);
    $this->assertDatabaseCount('failed_jobs', 0);
    Http::assertSentCount(3);
    Http::assertNotSent(fn (Request $request): bool => $request['messages'][1]['content'] !== 'Можно заменить приз деньгами?');
    expect(DB::table('jobs')->where('queue', 'telegram')->count())->toBe(1);
});

test('stops transient failures after three provider requests and creates one safe escalation', function (int $status) {
    config()->set('llm.endpoint', 'https://llm.example/exhausted');
    config()->set('llm.max_attempts', 99);
    config()->set('llm.retry_backoff', [0]);
    Http::preventStrayRequests();
    Http::fake(['https://llm.example/exhausted' => Http::response([], $status)]);
    $inbound = Message::factory()->create(['body' => 'Когда розыгрыш?']);
    ProcessIncomingMessage::dispatch($inbound->id);

    $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'ai', '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 99])->assertSuccessful();
    app()->call([new ProcessIncomingMessage($inbound->id), 'handle']);

    expect(SupportDecision::query()->sole()->reason)->toBe('llm_failure');
    $this->assertDatabaseCount('tickets', 1);
    $this->assertDatabaseCount('messages', 2);
    $this->assertDatabaseCount('support_decisions', 1);
    expect(DB::table('jobs')->where('queue', 'telegram')->count())->toBe(1);
    expect(Message::query()->where('author', MessageAuthor::Bot)->sole()->body)->toContain('передан оператору');
    Http::assertSentCount(3);
})->with(['rate limit' => [429], 'server error' => [503]]);
