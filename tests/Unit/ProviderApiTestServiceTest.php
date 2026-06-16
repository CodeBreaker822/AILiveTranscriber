<?php

namespace Tests\Unit;

use App\Services\ProviderApiTestService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProviderApiTestServiceTest extends TestCase
{
    public function test_it_returns_elevenlabs_connection_details(): void
    {
        Http::fake([
            'https://api.elevenlabs.io/v1/speech-to-text' => Http::response([
                'detail' => 'Missing file',
            ], 422),
            'https://api.elevenlabs.io/v1/user/subscription' => Http::response([
                'tier' => 'creator',
                'character_count' => 2500,
                'character_limit' => 10000,
                'billing_period' => 'monthly',
            ]),
        ]);

        $result = app(ProviderApiTestService::class)->testElevenLabs('test-key');

        $this->assertSame('connected', $result['status']);
        $this->assertSame(7500, $result['details']['characters_left']);
        $this->assertSame('creator', $result['details']['tier']);
    }

    public function test_it_accepts_elevenlabs_keys_with_restricted_subscription_access(): void
    {
        Http::fake([
            'https://api.elevenlabs.io/v1/speech-to-text' => Http::response([
                'detail' => 'Missing file',
            ], 422),
            'https://api.elevenlabs.io/v1/user/subscription' => Http::response([
                'detail' => 'Unauthorized',
            ], 401),
        ]);

        $result = app(ProviderApiTestService::class)->testElevenLabs('test-key');

        $this->assertSame('connected', $result['status']);
        $this->assertSame([], $result['details']);
        $this->assertSame(
            'ElevenLabs API key is connected. Subscription details are not available for this key.',
            $result['message'],
        );
    }

    public function test_it_returns_gemini_connection_details(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models?key=*' => Http::response([
                'models' => [
                    [
                        'name' => 'models/gemini-3.1-flash-lite',
                    ],
                ],
            ]),
        ]);

        $result = app(ProviderApiTestService::class)->testGemini('test-key', 'gemini-3.1-flash-lite');

        $this->assertSame('connected', $result['status']);
        $this->assertSame('gemini-3.1-flash-lite', $result['details']['model']);
        $this->assertTrue($result['details']['model_available']);
        $this->assertArrayNotHasKey('usage_note', $result['details']);
        $this->assertArrayNotHasKey('test_prompt_tokens', $result['details']);
    }
}
