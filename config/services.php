<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'embedding_model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small'),
        'timeout' => (int) env('OPENAI_TIMEOUT', 20),
        'daily_limit' => (int) env('OPENAI_DAILY_EMBEDDING_LIMIT', 10000),
    ],

    /** Внешний чат-агент (CRM модерация описаний и др.) — ключ только на сервере. */
    'site_al' => [
        'base_url' => rtrim(env('SITE_AL_BASE_URL', 'https://site-al.ru/api/v1'), '/'),
        'api_key' => env('SITE_AL_API_KEY'),
        'agent_id' => env('SITE_AL_AGENT_ID'),
        'timeout' => (int) env('SITE_AL_TIMEOUT', 120),
        /** Vision / product-photos/verify может работать дольше чата. */
        'photo_verify_timeout' => (int) env('SITE_AL_PHOTO_VERIFY_TIMEOUT', 180),
    ],

    /** Локальный Ollama (OpenAI-совместимый /v1/chat/completions). Базовый URL можно переопределить в карточке интеграции CRM. */
    'ollama' => [
        'base_url' => rtrim(env('OLLAMA_BASE_URL', 'http://127.0.0.1:11434'), '/'),
        'timeout' => (int) env('OLLAMA_TIMEOUT', 120),
    ],

];
