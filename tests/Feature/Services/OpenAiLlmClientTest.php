<?php

use App\Data\SupportLlmRequest;
use App\Enums\LlmAnalysisKind;
use App\Exceptions\InvalidLlmDecisionException;
use App\Exceptions\LlmRequestException;
use App\Services\OpenAiLlmClient;
use App\Services\PromotionRules;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('llm.endpoint', 'https://llm.example/v1/chat/completions');
    config()->set('llm.api_key', 'test-key');
    config()->set('llm.model', 'test-model');
});

test('maps a valid provider response to validated analysis parts', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://llm.example/v1/chat/completions' => Http::response(providerResponse(analysisResponse([
            ['kind' => 'rule_answer', 'answer' => 'Кефир не участвует.', 'source_rules' => ['4.1']],
        ]))),
    ]);

    $analysis = app(OpenAiLlmClient::class)->analyze(new SupportLlmRequest('кефир участвует?', 'Правила'));

    expect($analysis->parts)->toHaveCount(1)
        ->and($analysis->parts[0]->kind)->toBe(LlmAnalysisKind::RuleAnswer);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://llm.example/v1/chat/completions'
        && $request['model'] === 'test-model'
        && str_contains($request['messages'][0]['content'], 'кефир участвует?'));
});

test('includes the part-based mixed delivery and prize replacement contract in the prompt', function () {
    $message = 'Я выиграл йогуртницу месяц назад, доставки до сих пор нет. Можно вместо неё получить деньги?';
    Http::preventStrayRequests();
    Http::fake([
        'https://llm.example/v1/chat/completions' => Http::response(providerResponse(analysisResponse([
            ['kind' => 'participant_specific', 'answer' => null, 'source_rules' => []],
            ['kind' => 'rule_answer', 'answer' => 'Выплата денежного эквивалента призов и замена призов другими не производятся.', 'source_rules' => ['7.4']],
        ]))),
    ]);

    $analysis = app(OpenAiLlmClient::class)->analyze(new SupportLlmRequest($message, app(PromotionRules::class)->content()));

    expect($analysis->parts)->toHaveCount(2)
        ->and($analysis->parts[0]->kind)->toBe(LlmAnalysisKind::ParticipantSpecific)
        ->and($analysis->parts[1]->sourceRules)->toBe(['7.4']);
    Http::assertSent(fn (Request $request): bool => str_contains($request['messages'][0]['content'], $message)
        && str_contains($request['messages'][0]['content'], 'do not choose a final decision type')
        && str_contains($request['messages'][0]['content'], 'participant_specific'));
});

test('maps a timeout to a typed provider error', function () {
    Http::preventStrayRequests();
    Http::fake(['https://llm.example/v1/chat/completions' => Http::failedConnection()]);

    expect(fn () => app(OpenAiLlmClient::class)->analyze(new SupportLlmRequest('Вопрос', 'Правила')))
        ->toThrow(LlmRequestException::class);
});

test('maps rate limits and server errors to typed provider errors', function (int $status) {
    Http::preventStrayRequests();
    Http::fake(['https://llm.example/v1/chat/completions' => Http::response([], $status)]);

    expect(fn () => app(OpenAiLlmClient::class)->analyze(new SupportLlmRequest('Вопрос', 'Правила')))
        ->toThrow(LlmRequestException::class);
})->with([
    'rate limit' => [429],
    'server error' => [503],
]);

test('rejects malformed provider content', function (array $response) {
    Http::preventStrayRequests();
    Http::fake(['https://llm.example/v1/chat/completions' => Http::response($response)]);

    expect(fn () => app(OpenAiLlmClient::class)->analyze(new SupportLlmRequest('Вопрос', 'Правила')))
        ->toThrow(InvalidLlmDecisionException::class);
})->with([
    'missing content' => [['choices' => []]],
    'invalid JSON' => [providerResponse('not-json')],
]);

/**
 * @param  array<string, mixed>|string  $content
 * @return array<string, mixed>
 */
function providerResponse(array|string $content): array
{
    return ['choices' => [['message' => ['content' => is_array($content) ? json_encode($content, JSON_THROW_ON_ERROR) : $content]]]];
}

/**
 * @param  list<array{kind: string, answer: ?string, source_rules: list<string>}>  $parts
 * @return array{parts: list<array{kind: string, answer: ?string, source_rules: list<string>}>}
 */
function analysisResponse(array $parts): array
{
    return ['parts' => $parts];
}
