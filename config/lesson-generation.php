<?php

return [
    'provider' => env('LESSON_GENERATOR', 'openai'),
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_LESSON_MODEL', 'gpt-5.6-sol'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'timeout' => (int) env('OPENAI_TIMEOUT', 180),
        'max_output_tokens' => (int) env('OPENAI_LESSON_MAX_OUTPUT_TOKENS', 24000),
    ],
];
