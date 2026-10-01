<?php

namespace App\Data;

use App\Enums\SupportDecisionType;

final readonly class ValidatedSupportDecision
{
    /**
     * @param  list<string>  $sourceRules
     */
    public function __construct(
        public SupportDecisionType $type,
        public string $reason,
        public ?string $answer,
        public array $sourceRules,
    ) {}

    /**
     * @return array{type: string, reason: string, answer: ?string, source_rules: list<string>}
     */
    public function toStructuredOutput(): array
    {
        return [
            'type' => $this->type->value,
            'reason' => $this->reason,
            'answer' => $this->answer,
            'source_rules' => $this->sourceRules,
        ];
    }
}
