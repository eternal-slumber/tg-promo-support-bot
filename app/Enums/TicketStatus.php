<?php

namespace App\Enums;

enum TicketStatus: string
{
    case Open = 'open';
    case WaitingForUser = 'waiting_for_user';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Открыто',
            self::WaitingForUser => 'Ожидает ответа участника',
            self::Closed => 'Закрыто',
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return match ($this) {
            self::Open => in_array($status, [self::WaitingForUser, self::Closed], true),
            self::WaitingForUser => in_array($status, [self::Open, self::Closed], true),
            self::Closed => false,
        };
    }
}
