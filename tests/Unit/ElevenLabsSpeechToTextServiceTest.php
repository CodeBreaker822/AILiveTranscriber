<?php

namespace Tests\Unit;

use App\Exceptions\ElevenLabsSpeechToTextException;
use App\Services\ElevenLabsSpeechToTextService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ElevenLabsSpeechToTextServiceTest extends TestCase
{
    public function test_it_transcribes_audio_and_returns_text_with_timestamps(): void
    {
        config([
            'services.elevenlabs.key' => 'test-api-key',
            'services.elevenlabs.speech_to_text_url' => 'https://api.elevenlabs.io/v1/speech-to-text',
            'services.elevenlabs.speech_to_text_model' => 'scribe_v2',
            'services.elevenlabs.speech_to_text_models' => ['scribe_v2', 'scribe_v1'],
        ]);

        Http::fake([
            'https://api.elevenlabs.io/v1/speech-to-text' => Http::response([
                'text' => 'Hello world!',
                'words' => [
                    [
                        'text' => 'Hello',
                        'start' => 0,
                        'end' => 0.5,
                        'type' => 'word',
                        'speaker_id' => 'speaker_1',
                        'logprob' => -0.124,
                    ],
                ],
            ]),
        ]);

        $audioPath = tempnam(sys_get_temp_dir(), 'audio-');
        file_put_contents($audioPath, 'fake audio');

        try {
            $result = app(ElevenLabsSpeechToTextService::class)->transcribe($audioPath);
        } finally {
            @unlink($audioPath);
        }

        $this->assertSame('Hello world!', $result['text']);
        $this->assertSame([
            [
                'text' => 'Hello',
                'start' => 0,
                'end' => 0.5,
                'type' => 'word',
                'speaker_id' => 'speaker_1',
            ],
        ], $result['timestamps']);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.elevenlabs.io/v1/speech-to-text'
                && $request->hasHeader('xi-api-key', 'test-api-key');
        });
    }

    public function test_it_rejects_unsupported_speech_to_text_models(): void
    {
        config([
            'services.elevenlabs.key' => 'test-api-key',
            'services.elevenlabs.speech_to_text_model' => 'unsupported-model',
            'services.elevenlabs.speech_to_text_models' => ['scribe_v2', 'scribe_v1'],
        ]);

        Http::fake();

        $audioPath = tempnam(sys_get_temp_dir(), 'audio-');
        file_put_contents($audioPath, 'fake audio');

        try {
            $this->expectException(ElevenLabsSpeechToTextException::class);
            $this->expectExceptionMessage('Unsupported ElevenLabs speech-to-text model.');

            app(ElevenLabsSpeechToTextService::class)->transcribe($audioPath);
        } finally {
            @unlink($audioPath);
        }
    }
}
