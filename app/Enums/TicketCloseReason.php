<?php

namespace App\Enums;

enum TicketCloseReason: string
{
    case UserConfirmed = 'user_confirmed';
    case AutoClosed = 'auto_closed';
    case OperatorClosed = 'operator_closed';

    public function label(): string
    {
        return match ($this) {
            self::UserConfirmed => 'Участник подтвердил решение',
            self::AutoClosed => 'Закрыто автоматически',
            self::OperatorClosed => 'Закрыто оператором',
        };
    }
}
