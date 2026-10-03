<?php

namespace App\Exceptions;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class SafeDatabaseException extends RuntimeException
{
    private function __construct(string $exceptionType, string $sqlState, string $file, int $line)
    {
        parent::__construct("database_query_failed [{$exceptionType}; SQLSTATE {$sqlState}] at {$file}:{$line}");
    }

    public static function from(Throwable $exception): ?self
    {
        do {
            if ($exception instanceof QueryException) {
                $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());

                return new self(
                    $exception::class,
                    preg_match('/^[A-Z0-9]{5}$/D', $sqlState) === 1 ? $sqlState : 'unknown',
                    $exception->getFile(),
                    $exception->getLine(),
                );
            }
        } while (($exception = $exception->getPrevious()) !== null);

        return null;
    }

    /** Only allowlisted diagnostics cross logging and failed-job boundaries. */
    public function report(): void
    {
        Log::error($this->getMessage());
    }

    public function __toString(): string
    {
        return $this->getMessage();
    }
}
