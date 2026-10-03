<?php

namespace App\Services;

use App\Data\TelegramOutboundMessage;
use App\Data\TelegramSentMessage;

interface TelegramBotClient
{
    public function sendMessage(TelegramOutboundMessage $message): TelegramSentMessage;
}
