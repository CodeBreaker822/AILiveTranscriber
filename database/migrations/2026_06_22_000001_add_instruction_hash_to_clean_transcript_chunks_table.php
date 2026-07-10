<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clean_transcript_chunks', function (Blueprint $table) {
            $table->string('instruction_hash', 64)->nullable()->after('model');
            $table->index('instruction_hash');
        });
    }

    public function down(): void
    {
        Schema::table('clean_transcript_chunks', function (Blueprint $table) {
            $table->dropIndex(['instruction_hash']);
            $table->dropColumn('instruction_hash');
        });
    }
};
