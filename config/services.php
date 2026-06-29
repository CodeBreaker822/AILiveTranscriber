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

    'transcription_api' => [
        'base_url' => env('TRANSCRIPTION_API_BASE_URL', 'https://dilgaims.site/api'),
        'timeout' => env('TRANSCRIPTION_API_TIMEOUT', 120),
    ],

    'silero_vad' => [
        'binary' => env('SILERO_VAD_BINARY'),
        'threshold' => env('SILERO_VAD_THRESHOLD', 0.5),
        'min_speech_ms' => env('SILERO_VAD_MIN_SPEECH_MS', 250),
        'min_silence_ms' => env('SILERO_VAD_MIN_SILENCE_MS', 500),
        'speech_pad_ms' => env('SILERO_VAD_SPEECH_PAD_MS', 80),
        'timeout' => env('SILERO_VAD_TIMEOUT', 30),
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
