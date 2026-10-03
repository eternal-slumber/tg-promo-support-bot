<?php

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(LazilyRefreshDatabase::class);

test('evaluates exactly 25 sanitized requests without Telegram delivery or retained data', function () {
    config()->set('llm.endpoint', 'https://llm.example/evaluate');
    config()->set('llm.model', 'test-model');
    Http::preventStrayRequests();
    Http::fake(['https://llm.example/evaluate' => Http::response([
        'choices' => [['message' => ['content' => json_encode(['decision' => 'escalate', 'reason' => 'participant_specific', 'answer' => null, 'evidence' => []], JSON_THROW_ON_ERROR)]]],
    ])]);
    $path = tempnam(sys_get_temp_dir(), 'evaluation');

    try {
        $this->artisan('support:evaluate', ['--output' => $path])->assertSuccessful();
        $report = file_get_contents($path);
        preg_match_all('/^\| (\d+) \|/m', $report, $rows);
        expect(array_map('intval', $rows[1]))->toBe(range(1, 25));
        expect($report)->toContain('[REDACTED_PAYMENT_CARD]', 'test-model')->not->toContain('2200 1234 5678 9012');
        foreach (['messages', 'tickets', 'support_decisions', 'telegram_updates', 'telegram_participants', 'jobs'] as $table) {
            expect(DB::table($table)->count())->toBe(0);
        }
        Http::assertSentCount(25);
        Http::assertSent(fn (Request $request): bool => str_contains($request['messages'][1]['content'], '[REDACTED_PAYMENT_CARD]'));
        Http::assertNotSent(fn (Request $request): bool => str_contains(json_encode($request->data()), '2200 1234 5678 9012'));
    } finally {
        unlink($path);
    }
});

test('evaluation uses job retries for temporary failures and reports permanent and exhausted failures separately from model results', function () {
    config()->set('llm.endpoint', 'https://llm.example/evaluate-retries');
    config()->set('llm.max_attempts', 3);
    config()->set('llm.retry_backoff', [0]);
    Http::preventStrayRequests();
    $counts = [];
    Http::fake(['https://llm.example/evaluate-retries' => function (Request $request) use (&$counts) {
        $text = $request['messages'][1]['content'];
        $counts[$text] = ($counts[$text] ?? 0) + 1;
        if ($text === 'До какого числа можно регистрировать чеки?' && $counts[$text] === 1) {
            return Http::response([], 503);
        }
        if ($text === 'кефир участвует?') {
            return Http::response([], 401);
        }
        if ($text === 'а сколько чеков в день можно загрузить') {
            return Http::response([], 500);
        }
        if ($text === 'Здравствуйте! Подскажите когда будет розыгрыш главного приза' && $counts[$text] === 1) {
            return Http::response(['choices' => [['message' => ['content' => 'invalid JSON']]]]);
        }

        return Http::response(['choices' => [['message' => ['content' => json_encode(['decision' => 'answer', 'reason' => 'rule_answer', 'answer' => 'Срок регистрации', 'evidence' => [['rule_id' => '2.3', 'quote' => 'Период регистрации чеков']]], JSON_THROW_ON_ERROR)]]]]);
    }]);
    $path = tempnam(sys_get_temp_dir(), 'evaluation-retries');
    try {
        $this->artisan('support:evaluate', ['--output' => $path])->assertSuccessful();
        $report = file_get_contents($path);
        expect($counts['До какого числа можно регистрировать чеки?'])->toBe(2);
        expect($counts['кефир участвует?'])->toBe(1);
        expect($counts['а сколько чеков в день можно загрузить'])->toBe(3);
        expect($counts['Здравствуйте! Подскажите когда будет розыгрыш главного приза'])->toBe(1);
        expect($report)->toContain('Model result', 'Infrastructure failure reason');
        expect($report)->toMatch('/\| 1 \|[^\n]+answer \/ rule_answer[^\n]+LLM HTTP 503[^\n]+\| 2 \|/');
        expect($report)->toMatch('/\| 2 \|[^\n]+нет валидного результата[^\n]+LLM HTTP 401[^\n]+\| 1 \|/');
        expect($report)->toMatch('/\| 3 \|[^\n]+нет валидного результата[^\n]+LLM HTTP 500[^\n]+\| 3 \|/');
        expect($report)->toMatch('/\| 4 \|[^\n]+нет валидного результата; validation: LLM provider response is not valid JSON\. \| нет \| 1 \|/');
        foreach (['messages', 'tickets', 'support_decisions', 'telegram_updates', 'telegram_participants', 'jobs', 'failed_jobs'] as $table) {
            expect(DB::table($table)->count())->toBe(0);
        }
        Http::assertSentCount(28);
    } finally {
        unlink($path);
    }
});
