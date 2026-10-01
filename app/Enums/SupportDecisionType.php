<?php

namespace App\Enums;

enum SupportDecisionType: string
{
    case Answer = 'answer';
    case Escalate = 'escalate';
    case Mixed = 'mixed';
    case Refuse = 'refuse';
}
