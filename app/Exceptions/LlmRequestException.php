<?php

namespace App\Exceptions;

use RuntimeException;

class LlmRequestException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retryable = true,
    ) {
        parent::__construct($message);
    }
}
