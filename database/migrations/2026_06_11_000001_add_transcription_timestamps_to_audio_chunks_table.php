<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('audio_chunks')) {
            return;
        }

        if (! Schema::hasColumn('audio_chunks', 'transcription_timestamps')) {
            Schema::table('audio_chunks', function (Blueprint $table) {
                $table->json('transcription_timestamps')->nullable()->after('translated_text');
            });
        }

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE audio_chunks MODIFY audio_blob LONGBLOB NOT NULL');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('audio_chunks') || ! Schema::hasColumn('audio_chunks', 'transcription_timestamps')) {
            return;
        }

        Schema::table('audio_chunks', function (Blueprint $table) {
            $table->dropColumn('transcription_timestamps');
        });
    }
};
