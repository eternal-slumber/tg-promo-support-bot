<?php

namespace App\Data;

final readonly class TelegramOutboundMessage
{
    public const int MaxTextLength = 4096;

    /**
     * @param  array<string, mixed>|null  $replyMarkup
     */
    public function __construct(
        public int $chatId,
        public string $text,
        public ?array $replyMarkup = null,
    ) {}

    public function exceedsTextLimit(): bool
    {
        return mb_strlen($this->text, 'UTF-8') > self::MaxTextLength;
    }
}
