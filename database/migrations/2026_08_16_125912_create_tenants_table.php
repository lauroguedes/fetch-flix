<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('status')->default('active');

            // Burst control, per tenant. Distinct from the daily API quota below:
            // this protects our own resolver capacity, that protects a shared
            // upstream allowance.
            $table->unsignedInteger('rate_limit_rpm')->default(60);
            $table->unsignedInteger('discover_rpm')->default(30);

            // Daily YouTube Data API units this tenant may spend. The sum of all
            // tenant budgets is deliberately kept below the global 10,000 so one
            // noisy consumer cannot spend everyone's allowance.
            $table->unsignedInteger('quota_units_daily')->default(2000);

            // Null means "no tenant-specific ceiling"; the request's own limits apply.
            $table->unsignedBigInteger('max_bytes')->nullable();
            $table->unsignedBigInteger('max_duration_ms')->nullable();

            // 0 means always degrade to the best available progressive stream
            // rather than erroring. Since YouTube removed itag 22, a 720p request
            // routinely lands on 360p and erroring would break every such call.
            $table->unsignedInteger('min_progressive_height')->default(0);

            $table->json('settings')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
