<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When a failed job may next be claimed.
     *
     * Without it a failure went straight back to 'queued' and the drain loop re-claimed it in
     * milliseconds, so one dead upstream burned the whole error budget in about a second and
     * disabled the provider — four "retries" that were really one failure counted four times.
     */
    public function up(): void
    {
        Schema::table('feed_queue', function (Blueprint $table) {
            $table->timestamp('retry_after')->nullable()->after('state');
            $table->index(['state', 'retry_after']);   // the claim query filters on both
        });
    }

    public function down(): void
    {
        Schema::table('feed_queue', function (Blueprint $table) {
            $table->dropIndex(['state', 'retry_after']);
            $table->dropColumn('retry_after');
        });
    }
};
