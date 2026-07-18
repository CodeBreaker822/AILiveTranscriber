<?php

namespace App\Http\Controllers;

use App\Services\Transcripts\TranscriptMemoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TranscriptMemoryController extends Controller
{
    public function clear(Request $request, TranscriptMemoryService $transcriptMemory): RedirectResponse
    {
        $removed = $transcriptMemory->purgeTranscriptText();

        return redirect()
            ->route($request->string('return_to')->toString() === 'workspace' ? 'transcription.workspace' : 'settings.edit')
            ->with(
                'status',
                "Transcript text cleared. Removed {$removed['formatted_size']} of transcript data.",
            );
    }
}
