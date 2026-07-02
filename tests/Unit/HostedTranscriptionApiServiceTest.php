<?php

namespace Tests\Unit;

use App\Services\AppSettingsService;
use App\Exceptions\SpeechToTextException;
use App\Exceptions\TranscriptPolisherException;
use App\Services\HostedTranscriptionApiService;
use App\Services\TranscriptPolisherService;
use App\Services\SpeechToTextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\ConnectionException;
use Tests\TestCase;

class HostedTranscriptionApiServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_checks_license_status_with_bearer_license_key(): void
    {
        config(['services.transcription_api.base_url' => 'https://dilgaims.site/api']);

        Http::fake([
            'https://dilgaims.site/api/license/status' => Http::response([
                'valid' => true,
                'active' => true,
                'expired' => false,
            ]),
        ]);

        $status = app(HostedTranscriptionApiService::class)->licenseStatus('license-123');

        $this->assertTrue($status['valid']);

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'GET'
                && $request->url() === 'https://dilgaims.site/api/license/status'
                && $request->hasHeader('Authorization', 'Bearer license-123');
        });
    }

    public function test_connectivity_probe_does_not_send_the_license_key(): void
    {
        config(['services.transcription_api.base_url' => 'https://dilgaims.site/api']);
        app(AppSettingsService::class)->setLicenseKey('license-123');

        Http::fake([
            'https://dilgaims.site/api' => Http::response([], 404),
        ]);

        $this->assertTrue(app(HostedTranscriptionApiService::class)->serverIsReachable());

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://dilgaims.site/api'
                && ! $request->hasHeader('Authorization');
        });
    }

    public function test_it_requests_the_update_zip_with_bearer_license_key(): void
    {
        config(['services.transcription_api.base_url' => 'https://dilgaims.site/api']);
        app(AppSettingsService::class)->setLicenseKey('license-123');

        Http::fake([
            'https://dilgaims.site/api/transcribe/update/zipfile' => Http::response('zip bytes'),
        ]);

        $response = app(HostedTranscriptionApiService::class)->downloadUpdateArchive();

        $this->assertSame('zip bytes', $response->body());

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'GET'
                && $request->url() === 'https://dilgaims.site/api/transcribe/update/zipfile'
                && $request->hasHeader('Authorization', 'Bearer license-123');
        });
    }

    public function test_it_transcribes_audio_through_hosted_api(): void
    {
        config(['services.transcription_api.base_url' => 'https://dilgaims.site/api']);

        $settings = app(AppSettingsService::class);
        $settings->setLicenseKey('license-123');
        $settings->setLicenseStatus($this->licenseStatusPayload());
        $settings->setSpeechToTextProvider('deepgram');
        $settings->setSpeechToTextModel('nova-3');

        $audioPath = tempnam(sys_get_temp_dir(), 'aitranscriber-hosted-');
        file_put_contents($audioPath, 'fake audio bytes');

        Http::fake([
            'https://dilgaims.site/api/transcribe*' => Http::response([
                'text' => 'Hello from the server.',
                'timestamps' => [
                    ['text' => 'Hello', 'start' => 0, 'end' => 0.25],
                ],
                'provider' => 'deepgram',
                'model' => 'nova-3',
            ]),
        ]);

        try {
            $result = app(SpeechToTextService::class)->transcribe($audioPath, [
                'language_code' => 'en',
                'clip_index' => 7,
                'clip_start_ms' => 360000,
                'clip_end_ms' => 420000,
            ]);
        } finally {
            @unlink($audioPath);
        }

        $this->assertSame('Hello from the server.', $result['text']);
        $this->assertSame('Hello', $result['timestamps'][0]['text']);

        Http::assertSent(function (Request $request): bool {
            $body = $request->body();

            return $request->method() === 'POST'
                && $request->url() === 'https://dilgaims.site/api/transcribe'
                && $request->hasHeader('Authorization', 'Bearer license-123')
                && str_contains($body, 'name="provider"')
                && str_contains($body, 'deepgram')
                && str_contains($body, 'name="model"')
                && str_contains($body, 'nova-3')
                && str_contains($body, 'name="language_code"')
                && str_contains($body, 'en')
                && str_contains($body, 'name="clip_index"')
                && str_contains($body, '7');
        });
    }

    public function test_transcription_connection_details_are_logged_but_not_exposed(): void
    {
        config(['services.transcription_api.base_url' => 'https://dilgaims.site/api']);
        $settings = app(AppSettingsService::class);
        $settings->setLicenseKey('license-123');
        $settings->setLicenseStatus($this->licenseStatusPayload());
        $settings->setSpeechToTextProvider('deepgram');
        $settings->setSpeechToTextModel('nova-3');
        $audioPath = tempnam(sys_get_temp_dir(), 'aitranscriber-hosted-error-');
        file_put_contents($audioPath, 'fake audio bytes');
        $technicalError = 'SSL certificate problem: unable to get local issuer certificate';
        Http::fake(function () use ($technicalError): never {
            throw new ConnectionException($technicalError);
        });
        Log::spy();

        try {
            app(SpeechToTextService::class)->transcribe($audioPath, ['language_code' => 'en']);
            $this->fail('Expected hosted transcription to fail.');
        } catch (SpeechToTextException $exception) {
            $this->assertSame(
                'AITranscriber could not contact transcription server. Please try again shortly.',
                $exception->getMessage(),
            );
            $this->assertStringNotContainsString($technicalError, $exception->getMessage());
        } finally {
            @unlink($audioPath);
        }

        Log::shouldHaveReceived('error')->withArgs(
            fn (string $message, array $context): bool => $message === 'Hosted transcription connection failed.'
                && $context['ca_bundle_exists'] === true
                && collect($context['exception_chain'])->contains(
                    fn (array $entry): bool => $entry['message'] === $technicalError
                )
        )->once();
    }

    public function test_it_polishes_transcript_chunks_through_hosted_api(): void
    {
        config(['services.transcription_api.base_url' => 'https://dilgaims.site/api']);

        app(AppSettingsService::class)->setLicenseKey('license-123');

        Http::fake([
            'https://dilgaims.site/api/polish' => Http::response([
                'chunks' => [
                    [
                        'audio_chunk_id' => 10,
                        'text' => 'We should begin now.',
                        'timestamps' => [],
                    ],
                ],
                'provider' => 'openai',
                'model' => 'gpt-4.1-mini',
            ]),
        ]);

        $result = app(TranscriptPolisherService::class)->polishChunks(
            [[
                'id' => 10,
                'clip_index' => 1,
                'range_label' => '00:00-01:00',
                'text' => 'uh we should begin now',
                'timestamps' => [],
            ]],
            ['instructions' => 'Fix grammar.'],
        );

        $this->assertSame(10, $result['chunks'][0]['audio_chunk_id']);
        $this->assertSame('We should begin now.', $result['chunks'][0]['text']);
        $this->assertSame('openai', $result['provider']);
        $this->assertSame('gpt-4.1-mini', $result['model']);

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return $request->method() === 'POST'
                && $request->url() === 'https://dilgaims.site/api/polish'
                && $request->hasHeader('Authorization', 'Bearer license-123')
                && ($data['instruction'] ?? null) === 'Fix grammar.'
                && (int) ($data['chunks'][0]['audio_chunk_id'] ?? 0) === 10
                && ! array_key_exists('provider', $data)
                && ! array_key_exists('model', $data);
        });
    }

    public function test_empty_polish_uses_server_reported_current_polisher_metadata(): void
    {
        $settings = app(AppSettingsService::class);
        $settings->setLicenseStatus([
            'providers' => [
                'polishing' => [
                    [
                        'provider' => 'anthropic',
                        'configured' => true,
                        'enabled' => true,
                        'connected' => true,
                        'models' => [
                            ['id' => 'claude-sonnet-4'],
                        ],
                    ],
                ],
            ],
        ]);

        $result = app(TranscriptPolisherService::class)->polish('', [], [
            'instructions' => 'Fix grammar.',
        ]);

        $this->assertSame('', $result['text']);
        $this->assertSame('anthropic', $result['provider']);
        $this->assertSame('claude-sonnet-4', $result['model']);
    }

    public function test_successful_empty_polish_response_is_rejected(): void
    {
        config(['services.transcription_api.base_url' => 'https://dilgaims.site/api']);
        app(AppSettingsService::class)->setLicenseKey('license-123');
        Http::fake([
            'https://dilgaims.site/api/polish' => Http::response(['text' => ''], 200),
        ]);

        $this->expectException(TranscriptPolisherException::class);
        $this->expectExceptionMessage('successful response without polished text');

        app(TranscriptPolisherService::class)->polish('Text to polish.');
    }

    public function test_polish_failure_preserves_the_server_message_and_status(): void
    {
        config(['services.transcription_api.base_url' => 'https://dilgaims.site/api']);
        app(AppSettingsService::class)->setLicenseKey('license-123');
        Http::fake([
            'https://dilgaims.site/api/polish' => Http::response([
                'message' => 'Provider quota exhausted for this license.',
            ], 429),
        ]);

        try {
            app(TranscriptPolisherService::class)->polish('Text to polish.');
            $this->fail('Expected polishing to fail.');
        } catch (TranscriptPolisherException $exception) {
            $this->assertSame('Provider quota exhausted for this license.', $exception->getMessage());
            $this->assertSame(429, $exception->getCode());
        }
    }

    private function licenseStatusPayload(): array
    {
        return [
            'providers' => [
                'transcription' => [
                    [
                        'provider' => 'deepgram',
                        'name' => 'Deepgram',
                        'configured' => true,
                        'enabled' => true,
                        'connected' => true,
                        'models' => [
                            [
                                'id' => 'nova-3',
                                'label' => 'Nova-3',
                                'default_language_code' => 'multi',
                                'languages' => [
                                    ['code' => 'multi', 'label' => 'Multilingual'],
                                    ['code' => 'en', 'label' => 'English'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
