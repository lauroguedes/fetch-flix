<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Extractor observability.
     *
     * yt-dlp breaks whenever YouTube changes, often weekly. That is scheduled
     * maintenance, not an incident, but only if the breakage is visible before
     * users report it.
     */
    public function up(): void
    {
        Schema::create('extractor_health', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->default('youtube');

            // Recorded on every check so "started failing at 21:04" maps to a
            // specific binary rather than to a guess.
            $table->string('yt_dlp_version')->nullable();

            $table->boolean('ok');
            $table->string('failure_code')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('checked_at');

            $table->index(['provider', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extractor_health');
    }
};
