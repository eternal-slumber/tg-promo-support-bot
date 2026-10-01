<?php

namespace App\Services;

use App\Data\LlmAnalysisPart;
use App\Data\ValidatedLlmAnalysis;
use App\Data\ValidatedSupportDecision;
use App\Enums\LlmAnalysisKind;
use App\Enums\SupportDecisionType;

class SupportDecisionBuilder
{
    public function build(ValidatedLlmAnalysis $analysis): ValidatedSupportDecision
    {
        if ($this->has($analysis, LlmAnalysisKind::PromptInjection)) {
            return new ValidatedSupportDecision(SupportDecisionType::Refuse, 'prompt_injection', 'Я не могу выполнить этот запрос.', []);
        }

        $ruleParts = array_values(array_filter($analysis->parts, fn (LlmAnalysisPart $part): bool => $part->kind === LlmAnalysisKind::RuleAnswer));
        $hasUnresolvedPart = $this->has($analysis, LlmAnalysisKind::ParticipantSpecific)
            || $this->has($analysis, LlmAnalysisKind::NotInRules);

        if ($ruleParts !== [] && $hasUnresolvedPart) {
            return new ValidatedSupportDecision(SupportDecisionType::Mixed, 'mixed_request', $this->answers($ruleParts), $this->sourceRules($ruleParts));
        }

        if ($ruleParts !== []) {
            return new ValidatedSupportDecision(SupportDecisionType::Answer, 'rule_answer', $this->answers($ruleParts), $this->sourceRules($ruleParts));
        }

        return new ValidatedSupportDecision(
            SupportDecisionType::Escalate,
            $this->has($analysis, LlmAnalysisKind::ParticipantSpecific) ? 'participant_specific' : 'not_in_rules',
            null,
            [],
        );
    }

    private function has(ValidatedLlmAnalysis $analysis, LlmAnalysisKind $kind): bool
    {
        return collect($analysis->parts)->contains(fn (LlmAnalysisPart $part): bool => $part->kind === $kind);
    }

    /** @param list<LlmAnalysisPart> $parts */
    private function answers(array $parts): string
    {
        return implode("\n\n", array_values(array_unique(array_map(fn (LlmAnalysisPart $part): string => $part->answer, $parts))));
    }

    /**
     * @param  list<LlmAnalysisPart>  $parts
     * @return list<string>
     */
    private function sourceRules(array $parts): array
    {
        return array_values(array_unique(array_merge(...array_map(fn (LlmAnalysisPart $part): array => $part->sourceRules, $parts))));
    }
}
