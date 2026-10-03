<?php

namespace App\Enums;

enum MessageAuthor: string
{
    case Participant = 'participant';
    case Bot = 'bot';
    case Operator = 'operator';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Participant => 'Участник',
            self::Bot => 'Бот',
            self::Operator => 'Оператор',
            self::System => 'Система',
        };
    }
}
