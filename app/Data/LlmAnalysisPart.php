<?php

namespace App\Data;

use App\Enums\LlmAnalysisKind;

final readonly class LlmAnalysisPart
{
    /**
     * @param  list<string>  $sourceRules
     * @param  list<array{rule_id: string, quote: string}>  $evidence
     */
    public function __construct(
        public LlmAnalysisKind $kind,
        public ?string $answer,
        public array $sourceRules,
        public array $evidence = [],
    ) {}

    /**
     * @return array{kind: string, answer: ?string, evidence: list<array{rule_id: string, quote: string}>}
     */
    public function toStructuredOutput(): array
    {
        return [
            'kind' => $this->kind->value,
            'answer' => $this->answer,
            'evidence' => $this->evidence,
        ];
    }
}
