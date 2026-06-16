<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

class ProviderApiTestService
{
    public function testElevenLabs(string $apiKey): array
    {
        $apiKey = trim($apiKey);

        if ($apiKey === '') {
            return $this->notConnected('ElevenLabs API key is not configured.');
        }

        try {
            $authProbe = Http::withHeaders([
                'xi-api-key' => $apiKey,
            ])->timeout(10)
                ->post((string) config('services.elevenlabs.speech_to_text_url'), [
                    'model_id' => config('services.elevenlabs.speech_to_text_model', 'scribe_v2'),
                ]);
        } catch (Throwable $exception) {
            return $this->invalid('Could not connect to ElevenLabs.', [
                'error' => $exception->getMessage(),
            ]);
        }

        if (in_array($authProbe->status(), [401, 403], true)) {
            return $this->invalid('ElevenLabs rejected this API key.', [
                'status_code' => $authProbe->status(),
            ]);
        }

        if ($authProbe->serverError()) {
            return $this->invalid('ElevenLabs could not verify this API key right now.', [
                'status_code' => $authProbe->status(),
            ]);
        }

        $subscription = $this->elevenLabsSubscription($apiKey);

        if ($subscription === []) {
            return $this->connected(
                'ElevenLabs API key is connected. Subscription details are not available for this key.',
            );
        }

        $characterCount = (int) ($subscription['character_count'] ?? 0);
        $characterLimit = (int) ($subscription['character_limit'] ?? 0);
        $charactersLeft = $characterLimit > 0 ? max(0, $characterLimit - $characterCount) : null;
        $message = match (true) {
            $charactersLeft === 0 => 'ElevenLabs API key is connected, but no characters are left.',
            default => 'ElevenLabs API key is connected.',
        };

        return $this->connected($message, [
            'tier' => $subscription['tier'] ?? null,
            'characters_used' => $characterCount,
            'character_limit' => $characterLimit ?: null,
            'characters_left' => $charactersLeft,
            'billing_period' => $subscription['billing_period'] ?? null,
            'character_refresh_period' => $subscription['character_refresh_period'] ?? null,
        ]);
    }

    public function testGemini(string $apiKey, string $model): array
    {
        $apiKey = trim($apiKey);

        if ($apiKey === '') {
            return $this->notConnected('Gemini API key is not configured.');
        }

        $url = rtrim((string) config('services.gemini.base_url'), '/') . '/models?key=' . urlencode($apiKey);

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->timeout(10)
                ->get($url);
        } catch (Throwable $exception) {
            return $this->invalid('Could not connect to Gemini.', [
                'error' => $exception->getMessage(),
            ]);
        }

        if ($response->failed()) {
            return $this->invalid('Gemini rejected this API key.', [
                'status_code' => $response->status(),
                'model' => $model,
            ]);
        }

        $models = collect($response->json('models') ?? []);
        $modelAvailable = $models->contains(
            fn (array $availableModel): bool => ($availableModel['name'] ?? '') === 'models/'.$model,
        );

        return $this->connected('Gemini API key is connected.', [
            'model' => $model,
            'model_available' => $modelAvailable,
        ]);
    }

    private function connected(string $message, array $details = []): array
    {
        return [
            'status' => 'connected',
            'message' => $message,
            'details' => $this->compactDetails($details),
        ];
    }

    private function notConnected(string $message, array $details = []): array
    {
        return [
            'status' => 'not_connected',
            'message' => $message,
            'details' => $this->compactDetails($details),
        ];
    }

    private function invalid(string $message, array $details = []): array
    {
        return [
            'status' => 'invalid',
            'message' => $message,
            'details' => $this->compactDetails($details),
        ];
    }

    private function compactDetails(array $details): array
    {
        return array_filter($details, fn ($value): bool => $value !== null && $value !== '');
    }

    private function elevenLabsSubscription(string $apiKey): array
    {
        try {
            $response = Http::withHeaders([
                'xi-api-key' => $apiKey,
            ])->timeout(10)
                ->get('https://api.elevenlabs.io/v1/user/subscription');
        } catch (Throwable) {
            return [];
        }

        if ($response->failed()) {
            return [];
        }

        $subscription = $response->json();

        return is_array($subscription) ? $subscription : [];
    }
}
