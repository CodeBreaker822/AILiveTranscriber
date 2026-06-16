<?php

namespace Tests\Unit;

use App\Services\GeminiTranscriptCleanerService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiTranscriptCleanerServiceTest extends TestCase
{
    public function test_it_cleans_transcript_text_and_returns_timestamps(): void
    {
        config([
            'services.gemini.model' => 'gemini-3.1-flash-lite',
            'services.gemini.generate_content_url' => 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent',
        ]);

        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-lite:generateContent?key=*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                [
                                    'text' => json_encode([
                                        'text' => 'We should begin the meeting now.',
                                        'timestamps' => [
                                            [
                                                'text' => 'We',
                                                'start' => 0,
                                                'end' => 0.2,
                                                'type' => 'word',
                                                'speaker_id' => 'speaker_1',
                                            ],
                                        ],
                                    ]),
                                ],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $result = new GeminiTranscriptCleanerService(apiKey: 'test-gemini-key')->clean('uh we should um begin the meeting now', [
            [
                'text' => 'uh',
                'start' => 0,
                'end' => 0.1,
                'type' => 'word',
                'speaker_id' => 'speaker_1',
            ],
        ]);

        $this->assertSame('We should begin the meeting now.', $result['text']);
        $this->assertSame('We', $result['timestamps'][0]['text']);
        $this->assertSame('gemini-3.1-flash-lite', $result['model']);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-lite:generateContent?key=test-gemini-key';
        });
    }

    public function test_it_cleans_transcript_chunks_by_audio_chunk_id(): void
    {
        config([
            'services.gemini.model' => 'gemini-3.1-flash-lite',
            'services.gemini.generate_content_url' => 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent',
        ]);

        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-lite:generateContent?key=*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                [
                                    'text' => json_encode([
                                        'chunks' => [
                                            [
                                                'audio_chunk_id' => 10,
                                                'text' => 'We should begin now.',
                                                'timestamps' => [
                                                    [
                                                        'text' => 'We',
                                                        'start' => 0,
                                                        'end' => 0.2,
                                                        'type' => 'word',
                                                        'speaker_id' => 'speaker_1',
                                                    ],
                                                ],
                                            ],
                                            [
                                                'audio_chunk_id' => 11,
                                                'text' => 'The next topic is budget.',
                                                'timestamps' => [],
                                            ],
                                        ],
                                    ]),
                                ],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $result = new GeminiTranscriptCleanerService(apiKey: 'test-gemini-key')->cleanChunks([
            [
                'id' => 10,
                'range_label' => '00:00-01:00',
                'text' => 'uh we should begin now',
                'timestamps' => [],
            ],
            [
                'id' => 11,
                'range_label' => '01:00-02:00',
                'text' => 'um the next topic is budget',
                'timestamps' => [],
            ],
        ]);

        $this->assertCount(2, $result['chunks']);
        $this->assertSame(10, $result['chunks'][0]['audio_chunk_id']);
        $this->assertSame('The next topic is budget.', $result['chunks'][1]['text']);
        $this->assertSame('gemini-3.1-flash-lite', $result['model']);

        Http::assertSent(function (Request $request): bool {
            $payload = $request->data();
            $requestText = $payload['contents'][0]['parts'][0]['text'] ?? '';

            return str_contains($requestText, '"audio_chunk_id":10')
                && str_contains($requestText, '"audio_chunk_id":11');
        });
    }
}
