<?php

return [
    'provider' => env('LLM_PROVIDER', 'openai'),
    'endpoint' => env('LLM_ENDPOINT', 'https://api.openai.com/v1/chat/completions'),
    'model' => env('LLM_MODEL'),
    'api_key' => env('LLM_API_KEY'),
    'response_format' => env('LLM_RESPONSE_FORMAT', 'json_object'),
    'connect_timeout' => (int) env('LLM_CONNECT_TIMEOUT', 5),
    'timeout' => (int) env('LLM_TIMEOUT', 120),
    'max_attempts' => (int) env('LLM_MAX_ATTEMPTS', 3),
    'retry_backoff' => array_map('intval', explode(',', env('LLM_RETRY_BACKOFF', '5,15,30'))),
];
