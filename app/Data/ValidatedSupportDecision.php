<?php

namespace App\Data;

use App\Enums\SupportDecisionType;

final readonly class ValidatedSupportDecision
{
    /**
     * @param  list<array{rule_id: string, quote: string}>  $evidence
     */
    public function __construct(
        public SupportDecisionType $type,
        public string $reason,
        public ?string $answer,
        public array $evidence,
    ) {}

    /**
     * @return array{decision: string, reason: string, answer: ?string, evidence: list<array{rule_id: string, quote: string}>}
     */
    public function toStructuredOutput(): array
    {
        return [
            'decision' => $this->type->value,
            'reason' => $this->reason,
            'answer' => $this->answer,
            'evidence' => $this->evidence,
        ];
    }
}
