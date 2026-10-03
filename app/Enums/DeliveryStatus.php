<?php

namespace App\Enums;

enum DeliveryStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Ожидает отправки',
            self::Sent => 'Отправлено',
            self::Failed => 'Ошибка отправки',
            self::Cancelled => 'Отменено',
        };
    }
}
