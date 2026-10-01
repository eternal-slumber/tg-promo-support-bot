<?php

namespace App\Data;

final readonly class TelegramSentMessage
{
    public function __construct(public int $messageId) {}
}
