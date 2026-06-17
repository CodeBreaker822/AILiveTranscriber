<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('app:prepare-tauri-build', function () {
    $sqlitePath = database_path('database.sqlite');

    File::ensureDirectoryExists(dirname($sqlitePath));

    if (! File::exists($sqlitePath)) {
        File::put($sqlitePath, '');
    }

    Artisan::call('migrate', [
        '--force' => true,
        '--no-interaction' => true,
    ]);

    $deletedCleanChunks = 0;
    $deletedAudioChunks = 0;

    if (Schema::hasTable('clean_transcript_chunks')) {
        $deletedCleanChunks = DB::table('clean_transcript_chunks')->delete();
    }

    if (Schema::hasTable('audio_chunks')) {
        $deletedAudioChunks = DB::table('audio_chunks')->delete();
    }

    if (DB::connection()->getDriverName() === 'sqlite') {
        DB::statement('VACUUM');
    }

    $this->info("Prepared Tauri build database.");
    $this->line("Deleted clean transcript chunks: {$deletedCleanChunks}");
    $this->line("Deleted audio chunks: {$deletedAudioChunks}");
})->purpose('Remove transcript and audio records before packaging the Tauri app.');
