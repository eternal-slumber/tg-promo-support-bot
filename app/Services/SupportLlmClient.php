<?php

namespace App\Services;

use App\Data\SupportLlmRequest;
use App\Data\ValidatedSupportDecision;

interface SupportLlmClient
{
    public function analyze(SupportLlmRequest $request): ValidatedSupportDecision;
}
