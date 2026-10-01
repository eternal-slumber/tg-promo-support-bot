<?php

namespace App\Services;

use App\Data\LlmAnalysisPart;
use App\Data\ValidatedLlmAnalysis;
use App\Enums\LlmAnalysisKind;
use App\Exceptions\InvalidLlmDecisionException;

class LlmAnalysisValidator
{
    public function validate(mixed $structuredOutput): ValidatedLlmAnalysis
    {
        if (! is_array($structuredOutput)
            || count($structuredOutput) !== 1
            || ! array_key_exists('parts', $structuredOutput)
            || ! is_array($structuredOutput['parts'])
            || $structuredOutput['parts'] === []) {
            throw new InvalidLlmDecisionException('LLM response does not match the analysis contract.');
        }

        return new ValidatedLlmAnalysis(array_map(fn (mixed $part): LlmAnalysisPart => $this->part($part), $structuredOutput['parts']));
    }

    private function part(mixed $part): LlmAnalysisPart
    {
        if (! is_array($part)
            || count($part) !== 3
            || ! array_key_exists('kind', $part)
            || ! array_key_exists('answer', $part)
            || ! array_key_exists('source_rules', $part)
            || ! is_string($part['kind'])
            || ($part['answer'] !== null && ! is_string($part['answer']))
            || ! is_array($part['source_rules'])) {
            throw new InvalidLlmDecisionException('LLM analysis part is invalid.');
        }

        $kind = LlmAnalysisKind::tryFrom($part['kind']);

        if ($kind === null) {
            throw new InvalidLlmDecisionException('LLM analysis has an unknown kind.');
        }

        $answer = $part['answer'] === null ? null : trim($part['answer']);
        $sourceRules = $this->sourceRules($part['source_rules']);
        $valid = match ($kind) {
            LlmAnalysisKind::RuleAnswer => $answer !== null && $answer !== '' && $sourceRules !== [],
            LlmAnalysisKind::ParticipantSpecific, LlmAnalysisKind::NotInRules, LlmAnalysisKind::PromptInjection => $answer === null && $sourceRules === [],
        };

        if (! $valid) {
            throw new InvalidLlmDecisionException('LLM analysis part contains contradictory fields.');
        }

        return new LlmAnalysisPart($kind, $answer, $sourceRules);
    }

    /**
     * @param  list<mixed>  $sourceRules
     * @return list<string>
     */
    private function sourceRules(array $sourceRules): array
    {
        foreach ($sourceRules as $sourceRule) {
            if (! is_string($sourceRule) || trim($sourceRule) === '') {
                throw new InvalidLlmDecisionException('LLM analysis source rules must be non-empty strings.');
            }
        }

        return array_values($sourceRules);
    }
}
