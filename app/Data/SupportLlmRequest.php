<?php

namespace App\Data;

final readonly class SupportLlmRequest
{
    public function __construct(
        public string $participantMessage,
        public string $promotionRules,
    ) {}
}
