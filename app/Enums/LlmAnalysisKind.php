<?php

namespace App\Enums;

enum LlmAnalysisKind: string
{
    case RuleAnswer = 'rule_answer';
    case ParticipantSpecific = 'participant_specific';
    case NotInRules = 'not_in_rules';
    case PromptInjection = 'prompt_injection';
}
