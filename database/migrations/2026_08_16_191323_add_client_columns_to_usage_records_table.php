<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Records which consuming app made each call.
     *
     * The `client` block was already required by the contract but went nowhere,
     * so it cost callers something and bought us nothing. Stored, it answers
     * the question that actually comes up during an incident: is this every
     * consumer, or one app version that shipped a bad build?
     *
     * Still nothing about end users. Individual viewers are the consuming app's
     * concern and this service does not become a second copy of their data.
     */
    public function up(): void
    {
        Schema::table('usage_records', function (Blueprint $table) {
            $table->string('client_platform', 32)->nullable()->after('provider');
            $table->string('client_app_version', 32)->nullable()->after('client_platform');
            $table->string('client_api_version', 16)->nullable()->after('client_app_version');

            // Supports "show me the error rate for iOS 1.4.2 this week" without
            // a full scan.
            $table->index(['client_platform', 'client_app_version']);
        });
    }

    public function down(): void
    {
        Schema::table('usage_records', function (Blueprint $table) {
            $table->dropIndex(['client_platform', 'client_app_version']);
            $table->dropColumn(['client_platform', 'client_app_version', 'client_api_version']);
        });
    }
};
