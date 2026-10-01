<?php

namespace App\Data;

final readonly class SupportLlmRequest
{
    /**
     * @param  array<string, scalar|null>  $technicalContext
     */
    public function __construct(
        public string $participantMessage,
        public string $promotionRules,
        public array $technicalContext = [],
    ) {}
}
