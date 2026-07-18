<?php

namespace App\Http\Controllers;

use App\Services\Support\ServiceUserMessage;
use App\Services\Transcripts\TranscriptExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TranscriptExportController extends Controller
{
    public function store(Request $request, TranscriptExportService $exporter): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['nullable', 'integer', 'min:1'],
            'category_name' => ['required', 'string', 'max:120'],
            'mode' => ['nullable', 'string', 'in:raw,clean'],
            'format' => ['nullable', 'string', 'in:txt,excel,word'],
        ]);

        $userId = (int) ($validated['user_id'] ?? 1);
        $categoryName = trim((string) $validated['category_name']);
        $mode = (string) ($validated['mode'] ?? 'raw');
        $format = (string) ($validated['format'] ?? 'txt');

        try {
            $file = $exporter->build($userId, $categoryName, $mode, $format);
        } catch (\RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 404);
        }

        return response()->json($file);
    }
}
