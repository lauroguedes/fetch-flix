<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Durable second-level cache behind Redis.
     *
     * search.list costs 100 units against a 10,000/day quota shared by every
     * consumer, so a Redis flush must not turn into a quota event. This table
     * is what makes a cold cache survivable.
     */
    public function up(): void
    {
        Schema::create('cached_discoveries', function (Blueprint $table) {
            $table->id();
            $table->string('cache_key')->unique();
            $table->string('kind');
            $table->string('provider')->default('youtube');

            // The normalised page as returned upstream, before per-request
            // duration filtering. One stored page serves every
            // max_duration_ms variant across every tenant.
            $table->json('payload');
            $table->string('next_page_token')->nullable();

            $table->unsignedInteger('quota_units_spent')->default(0);
            $table->timestamp('fetched_at');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cached_discoveries');
    }
};
