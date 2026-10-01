<?php

namespace App\Data;

final readonly class TelegramIngestionResult
{
    public function __construct(
        public bool $duplicate,
        public bool $ignored,
        public ?int $messageId = null,
    ) {}
}
