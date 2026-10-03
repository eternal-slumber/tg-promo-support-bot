<?php

namespace App\Enums;

enum TelegramUpdateKind: string
{
    case Message = 'message';
    case NonTextMessage = 'non_text_message';
    case Unsupported = 'unsupported';
}
