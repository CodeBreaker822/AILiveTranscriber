<?php

namespace App\Http\Controllers;

use App\Jobs\RunUploadSessionJob;
use App\Services\BackgroundJobs\BackgroundJobStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UploadSessionController extends Controller
{
    public function store(Request $request, BackgroundJobStore $jobs): JsonResponse
    {
        @set_time_limit(0);

        $validated = $request->validate([
            'upload_session_id' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9-]+$/'],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'category_name' => ['required', 'string', 'max:120'],
            'duration_ms' => ['required', 'integer', 'min:1'],
            'chunk_seconds' => ['nullable', 'integer', 'min:1'],
            'transcription_engine' => ['nullable', 'string', Rule::in(['online', 'offline'])],
            'whisper_model' => ['nullable', 'string', 'in:tiny,small,medium,large,turbo'],
            'language_code' => ['nullable', 'string', 'max:32'],
            'use_vad' => ['nullable', 'boolean'],
            'use_diarization' => ['nullable', 'boolean'],
        ]);

        $job = $jobs->create('process_upload_session', $validated);
        dispatch(new RunUploadSessionJob($job['id'], $validated));

        return response()->json([
            'background' => true,
            'job_id' => $job['id'],
            'status' => $job['status'],
            'status_url' => route('background-jobs.show', ['job' => $job['id']]),
            'cancel_url' => route('background-jobs.cancel', ['job' => $job['id']]),
        ], 202);
    }
}
