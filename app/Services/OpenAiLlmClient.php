<?php

namespace App\Services;

use App\Data\SupportLlmRequest;
use App\Data\ValidatedLlmAnalysis;
use App\Exceptions\InvalidLlmDecisionException;
use App\Exceptions\LlmRequestException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use JsonException;

class OpenAiLlmClient implements SupportLlmClient
{
    public function __construct(
        private readonly PromotionRules $rules,
        private readonly LlmAnalysisValidator $validator,
    ) {}

    public function analyze(SupportLlmRequest $request): ValidatedLlmAnalysis
    {
        try {
            $response = Http::acceptJson()
                ->withToken((string) config('llm.api_key'))
                ->connectTimeout((int) config('llm.connect_timeout'))
                ->timeout((int) config('llm.timeout'))
                ->post((string) config('llm.endpoint'), [
                    'model' => config('llm.model'),
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        ['role' => 'system', 'content' => $this->rules->systemPrompt($request->promotionRules)],
                        ['role' => 'user', 'content' => $request->participantMessage],
                    ],
                ]);

            if ($response->status() === 429 || $response->serverError()) {
                throw new LlmRequestException('LLM provider is temporarily unavailable.');
            }

            $response->throw();
        } catch (ConnectionException $exception) {
            throw new LlmRequestException('LLM provider connection failed.', true, $exception);
        } catch (RequestException $exception) {
            throw new LlmRequestException('LLM provider rejected the request.', false, $exception);
        }

        $content = data_get($response->json(), 'choices.0.message.content');

        if (! is_string($content)) {
            throw new InvalidLlmDecisionException('LLM provider response has no structured content.');
        }

        try {
            $structuredOutput = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidLlmDecisionException('LLM provider response is not valid JSON.', 0, $exception);
        }

        return $this->validator->validate($structuredOutput, $request->promotionRules);
    }
}
