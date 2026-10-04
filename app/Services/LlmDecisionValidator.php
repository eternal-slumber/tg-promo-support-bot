<?php

namespace App\Services;

use App\Data\ValidatedSupportDecision;
use App\Enums\SupportDecisionType;
use App\Exceptions\InvalidLlmDecisionException;

class LlmDecisionValidator
{
    public const string RefusalAnswer = 'Я не могу выполнить этот запрос.';

    public function validate(mixed $structuredOutput, string $promotionRules): ValidatedSupportDecision
    {
        if (! is_array($structuredOutput)
            || count($structuredOutput) !== 4
            || ! isset($structuredOutput['decision'], $structuredOutput['reason'], $structuredOutput['evidence'])
            || ! array_key_exists('answer', $structuredOutput)
            || ! is_string($structuredOutput['decision'])
            || ! is_string($structuredOutput['reason'])
            || ($structuredOutput['answer'] !== null && ! is_string($structuredOutput['answer']))
            || ! is_array($structuredOutput['evidence'])
            || ! array_is_list($structuredOutput['evidence'])) {
            throw new InvalidLlmDecisionException('LLM response does not match the decision contract.');
        }

        $type = SupportDecisionType::tryFrom($structuredOutput['decision']);
        $allowedReasons = match ($type) {
            SupportDecisionType::Answer => ['rule_answer'],
            SupportDecisionType::Mixed => ['mixed_request'],
            SupportDecisionType::Escalate => ['participant_specific', 'not_in_rules'],
            SupportDecisionType::Refuse => ['prompt_injection'],
            null => [],
        };

        if (! in_array($structuredOutput['reason'], $allowedReasons, true)) {
            throw new InvalidLlmDecisionException('LLM decision or reason is invalid.');
        }

        $answer = $structuredOutput['answer'] === null ? null : trim($structuredOutput['answer']);
        $evidence = $this->evidence($structuredOutput['evidence'], (new PromotionRules)->catalog($promotionRules));
        $valid = match ($type) {
            SupportDecisionType::Answer, SupportDecisionType::Mixed => $answer !== null && $answer !== '' && $evidence !== [],
            SupportDecisionType::Escalate => $answer === null && $evidence === [],
            SupportDecisionType::Refuse => $answer === self::RefusalAnswer && $evidence === [],
        };

        if (! $valid) {
            throw new InvalidLlmDecisionException('LLM decision contains contradictory fields.');
        }

        return new ValidatedSupportDecision($type, $structuredOutput['reason'], $answer, $evidence);
    }

    /**
     * @param  list<mixed>  $evidence
     * @param  array<string, string>  $catalog
     * @return list<array{rule_id: string, quote: string}>
     */
    private function evidence(array $evidence, array $catalog): array
    {
        $verified = [];

        foreach ($evidence as $item) {
            if (! is_array($item) || count($item) !== 2
                || ! isset($item['rule_id'], $item['quote'])
                || ! is_string($item['rule_id']) || ! is_string($item['quote'])
                || ! isset($catalog[$item['rule_id']]) || trim($item['quote']) === '') {
                throw new InvalidLlmDecisionException('LLM grounding evidence is invalid.');
            }

            $quote = $this->normalizeWhitespace($item['quote']);

            if ($quote === '' || ! str_contains($this->normalizeWhitespace($catalog[$item['rule_id']]), $quote)) {
                throw new InvalidLlmDecisionException('LLM grounding quote is not in the referenced rule.');
            }

            $verified[] = ['rule_id' => $item['rule_id'], 'quote' => $quote];
        }

        return $verified;
    }

    private function normalizeWhitespace(string $text): string
    {
        return preg_replace('/\s+/u', ' ', trim($text)) ?? '';
    }
}
