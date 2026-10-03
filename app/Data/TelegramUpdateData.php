<?php

namespace App\Data;

use App\Enums\TelegramUpdateKind;

final readonly class TelegramUpdateData
{
    public function __construct(
        public int $updateId,
        public TelegramUpdateKind $kind,
        public ?int $telegramUserId = null,
        public ?int $chatId = null,
        public ?int $telegramMessageId = null,
        public ?string $text = null,
    ) {}

    public function isTextMessage(): bool
    {
        return $this->kind === TelegramUpdateKind::Message && $this->text !== null;
    }
}
