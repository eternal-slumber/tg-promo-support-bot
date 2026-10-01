<?php

namespace App\Enums;

enum TicketCloseReason: string
{
    case UserConfirmed = 'user_confirmed';
    case AutoClosed = 'auto_closed';
    case OperatorClosed = 'operator_closed';
}
