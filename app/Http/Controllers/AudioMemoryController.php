<?php

namespace App\Http\Controllers;

use App\Services\Audio\AudioMemoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AudioMemoryController extends Controller
{
    public function clearTemporary(Request $request, AudioMemoryService $audioMemory): RedirectResponse
    {
        $removed = $audioMemory->purgeTemporaryAudio();

        return redirect()
            ->route($request->string('return_to')->toString() === 'workspace' ? 'transcription.workspace' : 'settings.edit')
            ->with(
                'status',
                "Temporary audio cache cleared. Removed {$removed['formatted_size']} from {$removed['files']} files.",
            );
    }

    public function clearStored(Request $request, AudioMemoryService $audioMemory): RedirectResponse
    {
        $removed = $audioMemory->purgeStoredAudioRecords();

        return redirect()
            ->route($request->string('return_to')->toString() === 'workspace' ? 'transcription.workspace' : 'settings.edit')
            ->with(
                'status',
                "Stored audio data cleared. Removed {$removed['formatted_size']} from {$removed['records']} audio records.",
            );
    }

    public function clearAll(Request $request, AudioMemoryService $audioMemory): RedirectResponse
    {
        $removed = $audioMemory->purgeAllAudioData();

        return redirect()
            ->route($request->string('return_to')->toString() === 'workspace' ? 'transcription.workspace' : 'settings.edit')
            ->with(
                'status',
                "All audio data cleared. Removed {$removed['formatted_size']} from {$removed['temporary_files']} temporary files and {$removed['stored_records']} stored audio records.",
            );
    }
}
