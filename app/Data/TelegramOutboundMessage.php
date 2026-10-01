<?php

namespace App\Data;

final readonly class TelegramOutboundMessage
{
    /**
     * @param  array<string, mixed>|null  $replyMarkup
     */
    public function __construct(
        public int $chatId,
        public string $text,
        public ?array $replyMarkup = null,
    ) {}
}
