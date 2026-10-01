<?php

namespace App\Enums;

enum MessageAuthor: string
{
    case Participant = 'participant';
    case Bot = 'bot';
    case Operator = 'operator';
    case System = 'system';
}
