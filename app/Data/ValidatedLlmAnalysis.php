<?php

namespace App\Data;

final readonly class ValidatedLlmAnalysis
{
    /**
     * @param  list<LlmAnalysisPart>  $parts
     */
    public function __construct(public array $parts) {}

    /**
     * @return array{parts: list<array{kind: string, answer: ?string, source_rules: list<string>}>}
     */
    public function toStructuredOutput(): array
    {
        return ['parts' => array_map(fn (LlmAnalysisPart $part): array => $part->toStructuredOutput(), $this->parts)];
    }
}
