<?php

namespace App\Http\Controllers;

use App\Services\HostedApi\HostedTranscriptionApiService;
use App\Services\Speech\OfflineWhisperService;
use Illuminate\Http\JsonResponse;

class AppUpdateController extends Controller
{
    public function connectivity(HostedTranscriptionApiService $api, OfflineWhisperService $offlineWhisper): JsonResponse
    {
        // The PHP development server handles one request at a time. Avoid blocking
        // every local page load on an external network probe while Vite is active.
        $online = config('app.desktop_dev') || $api->serverIsReachable();

        return response()->json([
            'online' => $online,
            'offline_available' => $offlineWhisper->isAvailable(),
            'offline_model_available' => $offlineWhisper->modelIsAvailable(),
        ]);
    }
}
