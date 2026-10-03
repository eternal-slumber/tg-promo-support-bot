<?php

namespace App\Services;

use App\Data\SupportLlmRequest;
use App\Data\ValidatedSupportDecision;
use App\Exceptions\InvalidLlmDecisionException;
use App\Exceptions\LlmRequestException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use JsonException;
use stdClass;

class OpenAiLlmClient implements SupportLlmClient
{
    public function __construct(
        private readonly PromotionRules $rules,
        private readonly LlmDecisionValidator $validator,
    ) {}

    public function analyze(SupportLlmRequest $request): ValidatedSupportDecision
    {
        try {
            $response = Http::acceptJson()
                ->withToken((string) config('llm.api_key'))
                ->connectTimeout(min((int) config('llm.connect_timeout'), (int) config('llm.timeout')))
                ->timeout((int) config('llm.timeout'))
                ->post((string) config('llm.endpoint'), [
                    'model' => config('llm.model'),
                    'response_format' => config('llm.response_format') === 'json_schema'
                        ? ['type' => 'json_schema', 'json_schema' => [
                            'name' => 'support_decision',
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'decision' => ['type' => 'string', 'enum' => ['answer', 'mixed', 'escalate', 'refuse']],
                                    'reason' => ['type' => 'string', 'enum' => ['rule_answer', 'mixed_request', 'participant_specific', 'not_in_rules', 'prompt_injection']],
                                    'answer' => ['type' => ['string', 'null']],
                                    'evidence' => ['type' => 'array', 'items' => [
                                        'type' => 'object',
                                        'properties' => ['rule_id' => ['type' => 'string'], 'quote' => ['type' => 'string']],
                                        'required' => ['rule_id', 'quote'],
                                        'additionalProperties' => false,
                                    ]],
                                ],
                                'required' => ['decision', 'reason', 'answer', 'evidence'],
                                'additionalProperties' => false,
                            ],
                        ]]
                        : ['type' => config('llm.response_format')],
                    'messages' => [
                        ['role' => 'system', 'content' => $this->rules->systemPrompt($request->promotionRules, now('Europe/Moscow')->toIso8601String())],
                        ['role' => 'user', 'content' => $request->participantMessage],
                    ],
                ]);

            if ($response->status() === 429 || $response->serverError()) {
                throw new LlmRequestException('LLM provider is temporarily unavailable.');
            }

            $response->throw();
        } catch (ConnectionException) {
            throw new LlmRequestException('LLM provider connection failed.');
        } catch (RequestException) {
            throw new LlmRequestException('LLM provider rejected the request.', false);
        }

        $content = data_get($response->json(), 'choices.0.message.content');

        if (! is_string($content)) {
            throw new InvalidLlmDecisionException('LLM provider response has no structured content.');
        }

        try {
            $result = json_decode($content, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidLlmDecisionException('LLM provider response is not valid JSON.', 0, $exception);
        }

        if (! $result instanceof stdClass || ! is_array($result->evidence ?? null)) {
            throw new InvalidLlmDecisionException('LLM response does not match the decision contract.');
        }

        $structuredOutput = (array) $result;
        $structuredOutput['evidence'] = array_map(function (mixed $item): array {
            if (! $item instanceof stdClass) {
                throw new InvalidLlmDecisionException('LLM grounding evidence is invalid.');
            }

            return (array) $item;
        }, $result->evidence);

        return $this->validator->validate($structuredOutput, $request->promotionRules);
    }
}
