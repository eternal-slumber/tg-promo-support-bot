<?php

namespace App\Data;

final readonly class SanitizedText
{
    /**
     * @param  list<string>  $redactionTypes
     */
    public function __construct(
        public string $text,
        public bool $wasRedacted,
        public array $redactionTypes,
    ) {}
}
