<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How often a stored file has actually been handed out.
     *
     * Deliberately a count of deliveries and not a count of bytes. In
     * production the delivery endpoint answers with a redirect to a pre-signed
     * bucket URL, so the bytes never pass through this application and cannot
     * be measured here. A `bytes_served` column would be exact in local
     * development and silently zero in production, which is worse than not
     * having one; egress belongs to the storage provider's own billing.
     *
     * What this does answer is which files are worth keeping, which is the
     * question retention actually asks.
     */
    public function up(): void
    {
        Schema::table('media_assets', function (Blueprint $table) {
            $table->unsignedBigInteger('serve_count')->default(0)->after('checksum');
        });
    }

    public function down(): void
    {
        Schema::table('media_assets', function (Blueprint $table) {
            $table->dropColumn('serve_count');
        });
    }
};
