<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Permanent, cross-tenant record of a video's aspect.
     *
     * The dimensions of a published video never change, so this is written once
     * and read forever. Deliberately not tenant-scoped: the aspect of a public
     * video is the same for everybody, and sharing it is what keeps the oEmbed
     * lookup off the critical path for all but the first caller.
     */
    public function up(): void
    {
        Schema::create('video_dimensions', function (Blueprint $table) {
            $table->string('provider');
            $table->string('provider_video_id');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');

            // Which rung of the fallback chain produced this, so the share of
            // guessed dimensions is measurable rather than invisible.
            $table->string('source');

            $table->timestamp('created_at')->nullable();

            $table->primary(['provider', 'provider_video_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_dimensions');
    }
};
