<?php

namespace App\Services;

use App\Data\LlmAnalysisPart;
use App\Data\ValidatedLlmAnalysis;
use App\Enums\LlmAnalysisKind;
use App\Exceptions\InvalidLlmDecisionException;

class LlmAnalysisValidator
{
    public function validate(mixed $structuredOutput, string $promotionRules): ValidatedLlmAnalysis
    {
        if (! is_array($structuredOutput)
            || count($structuredOutput) !== 1
            || ! array_key_exists('parts', $structuredOutput)
            || ! is_array($structuredOutput['parts'])
            || $structuredOutput['parts'] === []
            || ! array_is_list($structuredOutput['parts'])) {
            throw new InvalidLlmDecisionException('LLM response does not match the analysis contract.');
        }

        $catalog = (new PromotionRules)->catalog($promotionRules);

        return new ValidatedLlmAnalysis(array_map(fn (mixed $part): LlmAnalysisPart => $this->part($part, $catalog), $structuredOutput['parts']));
    }

    /** @param array<string, string> $catalog */
    private function part(mixed $part, array $catalog): LlmAnalysisPart
    {
        if (! is_array($part)
            || count($part) !== 3
            || ! array_key_exists('kind', $part)
            || ! array_key_exists('answer', $part)
            || ! array_key_exists('evidence', $part)
            || ! is_string($part['kind'])
            || ($part['answer'] !== null && ! is_string($part['answer']))
            || ! is_array($part['evidence'])
            || ! array_is_list($part['evidence'])) {
            throw new InvalidLlmDecisionException('LLM analysis part is invalid.');
        }

        $kind = LlmAnalysisKind::tryFrom($part['kind']);

        if ($kind === null) {
            throw new InvalidLlmDecisionException('LLM analysis has an unknown kind.');
        }

        $answer = $part['answer'] === null ? null : trim($part['answer']);
        $evidence = $this->evidence($part['evidence'], $catalog);
        $valid = match ($kind) {
            LlmAnalysisKind::RuleAnswer => $answer !== null && $answer !== '' && $evidence !== [],
            LlmAnalysisKind::ParticipantSpecific, LlmAnalysisKind::NotInRules, LlmAnalysisKind::PromptInjection => $answer === null && $evidence === [],
        };

        if (! $valid) {
            throw new InvalidLlmDecisionException('LLM analysis part contains contradictory fields.');
        }

        $sourceRules = array_values(array_unique(array_column($evidence, 'rule_id')));

        return new LlmAnalysisPart(
            $kind,
            $kind === LlmAnalysisKind::RuleAnswer ? implode("\n\n", array_map(fn (string $ruleId): string => $catalog[$ruleId], $sourceRules)) : null,
            $sourceRules,
            $evidence,
        );
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
