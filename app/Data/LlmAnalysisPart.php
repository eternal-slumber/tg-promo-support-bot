<?php

namespace App\Data;

use App\Enums\LlmAnalysisKind;

final readonly class LlmAnalysisPart
{
    /**
     * @param  list<string>  $sourceRules
     */
    public function __construct(
        public LlmAnalysisKind $kind,
        public ?string $answer,
        public array $sourceRules,
    ) {}

    /**
     * @return array{kind: string, answer: ?string, source_rules: list<string>}
     */
    public function toStructuredOutput(): array
    {
        return [
            'kind' => $this->kind->value,
            'answer' => $this->answer,
            'source_rules' => $this->sourceRules,
        ];
    }
}
