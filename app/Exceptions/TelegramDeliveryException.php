<?php

namespace App\Exceptions;

use RuntimeException;

class TelegramDeliveryException extends RuntimeException
{
    public function __construct(
        public readonly string $safeError,
        public readonly bool $retryable,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($safeError);
    }
}
