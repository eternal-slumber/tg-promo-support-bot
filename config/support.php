<?php

return [
    'ticket_auto_close_hours' => (int) env('TICKET_AUTO_CLOSE_HOURS', 24),
    'operator' => [
        'email' => env('OPERATOR_EMAIL'),
        'password' => env('OPERATOR_PASSWORD'),
    ],
];
