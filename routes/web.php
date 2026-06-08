<?php

use App\Http\Controllers\AudioChunkController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/audio-chunks', [AudioChunkController::class, 'index'])->name('audio-chunks.index');
Route::post('/audio-chunks', [AudioChunkController::class, 'store'])->name('audio-chunks.store');
Route::get('/audio-chunks/{audioChunk}/audio', [AudioChunkController::class, 'audio'])->name('audio-chunks.audio');
Route::delete('/audio-chunks/{audioChunk}', [AudioChunkController::class, 'destroy'])->name('audio-chunks.destroy');
