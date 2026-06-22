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
    $sqliteSnapshotPath = base_path('build/tauri/database/database.sqlite');

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

    DB::disconnect();

    File::ensureDirectoryExists(dirname($sqliteSnapshotPath));
    File::copy($sqlitePath, $sqliteSnapshotPath);

    $this->info("Prepared Tauri build database.");
    $this->line("Deleted clean transcript chunks: {$deletedCleanChunks}");
    $this->line("Deleted audio chunks: {$deletedAudioChunks}");
    $this->line("Bundled SQLite snapshot: {$sqliteSnapshotPath}");
})->purpose('Remove transcript and audio records before packaging the Tauri app.');

Artisan::command('app:prepare-tauri-empty-build', function () {
    $sqliteSnapshotPath = base_path('build/tauri/database/database.sqlite');

    File::ensureDirectoryExists(dirname($sqliteSnapshotPath));
    File::delete($sqliteSnapshotPath);
    File::put($sqliteSnapshotPath, '');

    config([
        'database.default' => 'sqlite',
        'database.connections.sqlite.database' => $sqliteSnapshotPath,
        'database.connections.sqlite.url' => null,
    ]);

    DB::purge('sqlite');
    DB::setDefaultConnection('sqlite');

    Artisan::call('migrate', [
        '--database' => 'sqlite',
        '--force' => true,
        '--no-interaction' => true,
    ]);

    if (Schema::connection('sqlite')->hasTable('app_settings')) {
        DB::connection('sqlite')->table('app_settings')->delete();
    }

    DB::connection('sqlite')->statement('VACUUM');
    DB::disconnect('sqlite');

    $this->info('Prepared empty Tauri build database.');
    $this->line("Bundled SQLite snapshot: {$sqliteSnapshotPath}");
    $this->line('Default API keys included: no');
})->purpose('Create a migrated Tauri database without default API keys or user data.');
