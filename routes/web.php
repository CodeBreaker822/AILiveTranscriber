<?php

use App\Http\Controllers\AudioChunkController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TranscriptFurnishController;
use App\Http\Controllers\UploadedAudioTranscriptionController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
})->name('transcription.live');

Route::get('/upload', function () {
    return view('pages.upload');
})->name('transcription.upload');

Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
Route::post('/settings', [SettingsController::class, 'update'])->name('settings.update');
Route::get('/settings/api-key-help', [SettingsController::class, 'help'])->name('settings.api-key-help');
Route::get('/audio-chunks', [AudioChunkController::class, 'index'])->name('audio-chunks.index');
Route::post('/audio-chunks', [AudioChunkController::class, 'store'])->name('audio-chunks.store');
Route::get('/audio-chunks/{audioChunk}/audio', [AudioChunkController::class, 'audio'])->name('audio-chunks.audio');
Route::delete('/audio-chunks/{audioChunk}', [AudioChunkController::class, 'destroy'])->name('audio-chunks.destroy');
Route::post('/audio-uploads', [UploadedAudioTranscriptionController::class, 'store'])->name('audio-uploads.store');
Route::post('/transcripts/furnish', [TranscriptFurnishController::class, 'store'])->name('transcripts.furnish');
