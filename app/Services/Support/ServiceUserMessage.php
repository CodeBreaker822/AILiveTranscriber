<?php

namespace App\Services\Support;

class ServiceUserMessage
{
    private static function brandName(): string
    {
        return trim((string) config('app.brand_name', config('app.name', 'Transcriber'))) ?: 'Transcriber';
    }

    public static function missingApiKey(string $provider): string
    {
        return "Add your {$provider} API key in Settings before continuing.";
    }

    public static function cannotReachProvider(string $provider): string
    {
        return self::brandName()." could not contact {$provider}. Please try again shortly.";
    }

    public static function providerRejectedKey(string $provider): string
    {
        return "{$provider} did not accept the saved API key. Please check it in Settings.";
    }

    public static function providerBusy(string $provider): string
    {
        return "{$provider} is receiving too many requests right now. Please wait a minute and try again.";
    }

    public static function providerUnavailable(string $provider): string
    {
        return "{$provider} is not available right now. Please try again later.";
    }

    public static function transcriptionFailed(string $provider): string
    {
        return "{$provider} could not transcribe this audio. Please try again or use another audio file.";
    }

    public static function cleanerFailed(): string
    {
        return 'Transcript polisher could not clean the transcript. Please try again.';
    }

    public static function emptyCleanerResponse(): string
    {
        return 'Transcript polisher could not prepare a cleaned transcript. Please try again.';
    }

    public static function invalidCleanerResponse(): string
    {
        return 'Transcript polisher returned a response '.self::brandName().' could not use. Please try again.';
    }

    public static function cleanerMissingChunks(): string
    {
        return 'Transcript polisher did not finish cleaning every transcript section. Please try again.';
    }

    public static function audioReadFailed(): string
    {
        return self::brandName().' could not read this audio file. Please choose the file again and try.';
    }

    public static function audioPrepareFailed(): string
    {
        return self::brandName().' could not prepare this audio. Please try another audio file.';
    }

    public static function uploadSessionExpired(): string
    {
        return 'This upload session is no longer available. Please choose the audio file again.';
    }

    public static function unsupportedProviderModel(string $provider): string
    {
        return "The selected {$provider} transcription model is not available. Please reopen Settings and try again.";
    }

    public static function noRawTranscript(string $categoryName = ''): string
    {
        $scope = $categoryName !== '' ? " for \"{$categoryName}\"" : '';

        return "No raw transcription is ready to export{$scope}.";
    }

    public static function noCleanedTranscript(string $categoryName = ''): string
    {
        $scope = $categoryName !== '' ? " for \"{$categoryName}\"" : '';

        return "Polish the transcript before exporting the cleaned version{$scope}.";
    }
}
