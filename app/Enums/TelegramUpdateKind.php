<?php

namespace App\Enums;

enum TelegramUpdateKind: string
{
    case Message = 'message';
    case CallbackQuery = 'callback_query';
    case Unsupported = 'unsupported';
}
