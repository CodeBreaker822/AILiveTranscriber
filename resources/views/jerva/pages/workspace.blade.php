<x-jerva::app-layout :title="config('app.brand_name', 'JERVA Transcriber')" active-page="workspace" :focused-workspace="true">
    @include('jerva.pages.partials.transcription-chat-workspace', [
        'languageOptions' => $languageOptions,
        'whisperModels' => $whisperModels,
        'audioChunkSeconds' => $audioChunkSeconds,
        'apiBaseUrl' => $apiBaseUrl,
        'hasLicenseKey' => $hasLicenseKey,
        'licenseKeySuffix' => $licenseKeySuffix,
        'licenseStatusLabel' => $licenseStatusLabel,
        'licenseStatusMessage' => $licenseStatusMessage,
        'licenseRefreshError' => $licenseRefreshError,
        'transcriptionProviders' => $transcriptionProviders,
        'providerPayload' => $providerPayload,
        'selectedProvider' => $selectedProvider,
        'selectedModel' => $selectedModel,
        'resourceProfile' => $resourceProfile,
        'audioMemory' => $audioMemory,
        'transcriptMemory' => $transcriptMemory,
    ])
</x-jerva::app-layout>
