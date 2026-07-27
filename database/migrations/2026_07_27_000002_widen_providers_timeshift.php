<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The provider `timeshift` holds the timezone name captured from an Xtream server's server_info
     * (e.g. "Africa/Casablanca", "America/Argentina/Buenos_Aires"). The original varchar(16) was too
     * short for many real zone names, so adding such a provider 500'd on insert
     * (SQLSTATE[22001] Data too long). Widen it; the validator also caps the value defensively.
     */
    public function up(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            $table->string('timeshift', 64)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            $table->string('timeshift', 16)->nullable()->change();
        });
    }
};
