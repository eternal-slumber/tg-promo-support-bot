<?php

namespace App\Enums;

enum TicketStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Открыто',
            self::Resolved => 'Решено',
            self::Closed => 'Закрыто',
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return match ($this) {
            self::Open => in_array($status, [self::Resolved, self::Closed], true),
            self::Resolved => in_array($status, [self::Open, self::Closed], true),
            self::Closed => false,
        };
    }
}
