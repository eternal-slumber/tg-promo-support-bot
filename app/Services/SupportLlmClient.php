<?php

namespace App\Services;

use App\Data\SupportLlmRequest;
use App\Data\ValidatedLlmAnalysis;

interface SupportLlmClient
{
    public function analyze(SupportLlmRequest $request): ValidatedLlmAnalysis;
}
