<?php

return [

    'http' => [
        'ca_bundle' => env('AI_TRANSCRIBER_CA_BUNDLE', base_path('php/extras/ssl/cacert.pem')),
    ],

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

    'whisper' => [
        'binary' => env('AI_TRANSCRIBER_EXECUTABLE'),
        'model' => env('WHISPER_MODEL_PATH'),
        'model_directory' => env('WHISPER_MODEL_DIRECTORY'),
        'model_url' => env('WHISPER_MODEL_URL', 'https://huggingface.co/ggerganov/whisper.cpp/resolve/main/ggml-large-v3-turbo-q8_0.bin?download=true'),
        'fallback_model_url' => env('WHISPER_FALLBACK_MODEL_URL', 'https://hf-mirror.com/ggerganov/whisper.cpp/resolve/main/ggml-large-v3-turbo-q8_0.bin'),
        'model_base_url' => env('WHISPER_MODEL_BASE_URL', 'https://huggingface.co/ggerganov/whisper.cpp/resolve/main'),
        'fallback_model_base_url' => env('WHISPER_FALLBACK_MODEL_BASE_URL', 'https://hf-mirror.com/ggerganov/whisper.cpp/resolve/main'),
        'model_sha1' => env('WHISPER_MODEL_SHA1', '01bf15bedffe9f39d65c1b6ff9b687ea91f59e0e'),
        'model_min_bytes' => env('WHISPER_MODEL_MIN_BYTES'),
        'download_timeout' => env('WHISPER_MODEL_DOWNLOAD_TIMEOUT', 3600),
        'timeout' => env('WHISPER_TRANSCRIPTION_TIMEOUT', 1800),
        'threads' => env('AI_TRANSCRIBER_WHISPER_THREADS', 2),
        'memory_budget_mb' => env('AI_TRANSCRIBER_WHISPER_MEMORY_BUDGET_MB', 0),
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
