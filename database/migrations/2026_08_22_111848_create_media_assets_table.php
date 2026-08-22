<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per downloadable variant of a video, shared by every tenant.
     *
     * Two keys, deliberately, because they answer different questions:
     *
     * `asset_key` is derived from the *request* (provider, video, height,
     * audio-only), so a resolve can look it up before extracting anything. On a
     * hit the whole yt-dlp process is skipped and the endpoint answers from the
     * database in milliseconds, which is the difference between a warm resolve
     * and an 8s one.
     *
     * `path` is derived from the *selected format*, so two variant rows that
     * land on the same underlying file — routine on YouTube, where a 720p and a
     * 1080p request both fall back to itag 18 — reference one stored object
     * instead of two copies. Pruning therefore has to delete the row and the
     * file separately: the file only goes when nothing else points at it.
     *
     * Not tenant-scoped, for the same reason `video_dimensions` is not: the
     * bytes of a public video are identical for everybody, and sharing them is
     * the entire point. Nothing tenant-derived is stored here.
     */
    public function up(): void
    {
        Schema::create('media_assets', function (Blueprint $table) {
            $table->id();

            // sha256 hex of provider|video_id|max_height|audio_only.
            $table->string('asset_key', 64)->unique();

            $table->string('provider');
            $table->string('provider_video_id');
            $table->unsignedInteger('max_height');
            $table->boolean('audio_only')->default(false);

            $table->string('status')->default('pending');

            // Null until the download succeeds. `disk` is recorded per row so a
            // deployment that switches from local to R2 can still find, serve
            // and prune everything it wrote before the switch.
            $table->string('disk')->nullable();
            $table->string('path')->nullable();

            // The extractor's own id for the chosen format ("18" on YouTube).
            // Recorded because `path` is derived from it and because it is the
            // first thing worth knowing when a download starts failing.
            $table->string('format_id')->nullable();

            $table->unsignedBigInteger('bytes')->nullable();
            $table->string('mime_type')->nullable();
            $table->string('container')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->boolean('has_audio')->default(true);
            $table->boolean('has_video')->default(true);

            // Metadata carried on the row so a warm resolve can answer the full
            // contract without re-extracting.
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->string('title')->nullable();
            $table->string('author')->nullable();
            $table->string('thumbnail_url', 2048)->nullable();

            $table->string('checksum', 64)->nullable();

            $table->string('error_code')->nullable();
            $table->unsignedInteger('attempts')->default(0);

            $table->timestamp('downloaded_at')->nullable();

            // Drives retention. Storage costs money every month, unlike handing
            // back somebody else's URL, so an asset nobody has asked for has to
            // be collectable.
            $table->timestamp('last_accessed_at')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('last_accessed_at');
            $table->index('path');
            $table->index(['provider', 'provider_video_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_assets');
    }
};
