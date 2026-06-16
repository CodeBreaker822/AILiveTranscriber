<?php

namespace Tests\Unit;

use App\Exceptions\DeepgramSpeechToTextException;
use App\Services\DeepgramSpeechToTextService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DeepgramSpeechToTextServiceTest extends TestCase
{
    public function test_it_transcribes_audio_and_returns_text_with_timestamps(): void
    {
        config([
            'services.deepgram.key' => 'test-api-key',
            'services.deepgram.listen_url' => 'https://api.deepgram.com/v1/listen',
            'services.deepgram.speech_to_text_models' => ['nova-3', 'nova-2'],
        ]);

        Http::fake([
            'https://api.deepgram.com/v1/listen*' => Http::response([
                'results' => [
                    'channels' => [
                        [
                            'alternatives' => [
                                [
                                    'transcript' => 'Hello world.',
                                    'words' => [
                                        [
                                            'word' => 'hello',
                                            'punctuated_word' => 'Hello',
                                            'start' => 0,
                                            'end' => 0.5,
                                            'speaker' => 0,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $audioPath = tempnam(sys_get_temp_dir(), 'audio-');
        file_put_contents($audioPath, 'fake audio');

        try {
            $result = app(DeepgramSpeechToTextService::class)->transcribe($audioPath, [
                'language_code' => 'eng',
            ]);
        } finally {
            @unlink($audioPath);
        }

        $this->assertSame('Hello world.', $result['text']);
        $this->assertSame([
            [
                'text' => 'Hello',
                'start' => 0,
                'end' => 0.5,
                'type' => 'word',
                'speaker_id' => 'speaker_0',
            ],
        ], $result['timestamps']);

        Http::assertSent(function (Request $request): bool {
            return str_starts_with($request->url(), 'https://api.deepgram.com/v1/listen?')
                && str_contains($request->url(), 'model=nova-3')
                && str_contains($request->url(), 'language=en')
                && $request->hasHeader('Authorization', 'Token test-api-key')
                && $request->body() === 'fake audio';
        });
    }

    public function test_it_rejects_unsupported_speech_to_text_models(): void
    {
        config([
            'services.deepgram.speech_to_text_models' => ['nova-3', 'nova-2'],
        ]);

        Http::fake();

        $audioPath = tempnam(sys_get_temp_dir(), 'audio-');
        file_put_contents($audioPath, 'fake audio');

        try {
            $this->expectException(DeepgramSpeechToTextException::class);
            $this->expectExceptionMessage(
                'The selected Deepgram transcription model is not available. Please reopen Settings and try again.',
            );

            (new DeepgramSpeechToTextService(
                apiKey: 'test-api-key',
                modelId: 'unsupported-model',
            ))->transcribe($audioPath);
        } finally {
            @unlink($audioPath);
        }
    }

    public function test_it_returns_a_friendly_message_when_deepgram_cannot_be_reached(): void
    {
        config([
            'services.deepgram.listen_url' => 'https://api.deepgram.com/v1/listen',
            'services.deepgram.speech_to_text_models' => ['nova-3', 'nova-2'],
        ]);

        Http::fake(function () {
            throw new ConnectionException('cURL error 6: Could not resolve host.');
        });

        $audioPath = tempnam(sys_get_temp_dir(), 'audio-');
        file_put_contents($audioPath, 'fake audio');

        try {
            $this->expectException(DeepgramSpeechToTextException::class);
            $this->expectExceptionMessage(
                'AITranscriber could not reach Deepgram. Check your internet connection, then try again.',
            );

            (new DeepgramSpeechToTextService(apiKey: 'test-api-key'))->transcribe($audioPath);
        } finally {
            @unlink($audioPath);
        }
    }
}
