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

    'elevenlabs' => [
        'key' => null,
        'speech_to_text_url' => env('ELEVENLABS_SPEECH_TO_TEXT_URL', 'https://api.elevenlabs.io/v1/speech-to-text'),
        'speech_to_text_model' => env('ELEVENLABS_SPEECH_TO_TEXT_MODEL', 'scribe_v2'),
        'speech_to_text_models' => ['scribe_v2', 'scribe_v1'],
        'timeout' => env('ELEVENLABS_TIMEOUT', 120),
    ],

    'deepgram' => [
        'key' => null,
        'listen_url' => env('DEEPGRAM_LISTEN_URL', 'https://api.deepgram.com/v1/listen'),
        'model' => env('DEEPGRAM_SPEECH_TO_TEXT_MODEL', 'nova-3'),
        'speech_to_text_models' => ['nova-3', 'nova-2'],
        'timeout' => env('DEEPGRAM_TIMEOUT', 120),
    ],

    'gemini' => [
        'key' => null,
        'model' => 'gemini-3.1-flash-lite',
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        'generate_content_url' => env('GEMINI_GENERATE_CONTENT_URL', 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent'),
        'timeout' => 30,
        'max_retries' => 3,
        'rpm_limit' => env('GEMINI_RPM_LIMIT', 15),
        'rate_limit_key' => env('GEMINI_RATE_LIMIT_KEY', 'gemini_global_requests_per_minute'),
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

];
