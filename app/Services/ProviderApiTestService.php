<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProviderApiTestService
{
    public function testElevenLabs(string $apiKey): array
    {
        $apiKey = trim($apiKey);

        if ($apiKey === '') {
            return $this->notConnected(ServiceUserMessage::missingApiKey('ElevenLabs'));
        }

        try {
            $authProbe = Http::withHeaders([
                'xi-api-key' => $apiKey,
            ])->timeout(10)
                ->post((string) config('services.elevenlabs.speech_to_text_url'), [
                    'model_id' => config('services.elevenlabs.speech_to_text_model', 'scribe_v2'),
                ]);
        } catch (Throwable $exception) {
            $this->logConnectionFailure('ElevenLabs', $exception);

            return $this->notConnected(ServiceUserMessage::cannotReachProvider('ElevenLabs'));
        }

        if (in_array($authProbe->status(), [401, 403], true)) {
            return $this->invalid(ServiceUserMessage::providerRejectedKey('ElevenLabs'));
        }

        if ($authProbe->status() === 429) {
            return $this->invalid(ServiceUserMessage::providerBusy('ElevenLabs'));
        }

        if ($authProbe->serverError()) {
            return $this->notConnected(ServiceUserMessage::providerUnavailable('ElevenLabs'));
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
            return $this->notConnected(ServiceUserMessage::missingApiKey('Gemini'));
        }

        $url = rtrim((string) config('services.gemini.base_url'), '/') . '/models?key=' . urlencode($apiKey);

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->timeout(10)
                ->get($url);
        } catch (Throwable $exception) {
            $this->logConnectionFailure('Gemini', $exception);

            return $this->notConnected(ServiceUserMessage::cannotReachProvider('Gemini'));
        }

        if ($response->failed()) {
            return $this->invalid($this->userMessageForProviderCheckFailure('Gemini', $response->status()), [
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

    public function testDeepgram(string $apiKey, string $model): array
    {
        $apiKey = trim($apiKey);

        if ($apiKey === '') {
            return $this->notConnected(ServiceUserMessage::missingApiKey('Deepgram'));
        }

        $url = (string) config('services.deepgram.listen_url')
            .'?'.http_build_query(['model' => $model]);

        try {
            $authProbe = Http::withHeaders([
                'Authorization' => 'Token '.$apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(10)
                ->post($url, []);
        } catch (Throwable $exception) {
            $this->logConnectionFailure('Deepgram', $exception);

            return $this->notConnected(ServiceUserMessage::cannotReachProvider('Deepgram'), [
                'model' => $model,
            ]);
        }

        if (in_array($authProbe->status(), [401, 403], true)) {
            return $this->invalid(ServiceUserMessage::providerRejectedKey('Deepgram'), [
                'model' => $model,
            ]);
        }

        if ($authProbe->status() === 429) {
            return $this->invalid(ServiceUserMessage::providerBusy('Deepgram'), [
                'model' => $model,
            ]);
        }

        if ($authProbe->serverError()) {
            return $this->notConnected(ServiceUserMessage::providerUnavailable('Deepgram'), [
                'model' => $model,
            ]);
        }

        return $this->connected('Deepgram API key is connected.', [
            'model' => $model,
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

    private function userMessageForProviderCheckFailure(string $provider, int $status): string
    {
        return match (true) {
            in_array($status, [401, 403], true) => ServiceUserMessage::providerRejectedKey($provider),
            $status === 429 => ServiceUserMessage::providerBusy($provider),
            $status >= 500 => ServiceUserMessage::providerUnavailable($provider),
            default => "{$provider} could not be tested right now. Please check the saved key and try again.",
        };
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

    private function logConnectionFailure(string $provider, Throwable $exception): void
    {
        Log::warning("{$provider} API connection test failed.", [
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'curl_cainfo' => ini_get('curl.cainfo') ?: null,
            'openssl_cafile' => ini_get('openssl.cafile') ?: null,
            'curl_ca_bundle' => getenv('CURL_CA_BUNDLE') ?: null,
            'ssl_cert_file' => getenv('SSL_CERT_FILE') ?: null,
        ]);
    }
}
