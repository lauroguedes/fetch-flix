<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Billing and analytics ledger.
     *
     * Redis counters are the fast path for quota enforcement; this is the
     * durable record of what was actually spent and on whose behalf.
     *
     * Deliberately holds nothing about end users. Individual viewers are the
     * consuming app's concern, and this service does not become a second copy
     * of their user data.
     */
    public function up(): void
    {
        Schema::create('usage_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('endpoint');
            $table->string('provider')->nullable();

            $table->unsignedInteger('quota_units')->default(0);
            $table->boolean('cache_hit')->default(false);
            $table->unsignedSmallInteger('status_code');
            $table->string('error_code')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            $table->timestamp('created_at');

            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_records');
    }
};
