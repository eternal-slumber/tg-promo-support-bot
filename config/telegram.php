<?php

return [
    'bot_token' => env('TELEGRAM_BOT_TOKEN'),
    'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
    'api_base_url' => env('TELEGRAM_API_BASE_URL', 'https://api.telegram.org'),
    'connect_timeout' => (int) env('TELEGRAM_CONNECT_TIMEOUT', 5),
    'timeout' => (int) env('TELEGRAM_TIMEOUT', 30),
];
