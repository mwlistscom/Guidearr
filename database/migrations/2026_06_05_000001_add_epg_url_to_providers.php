<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            // Separate XMLTV/EPG source for m3u providers (xtream derives it from the panel URL).
            $table->string('epg_url', 1024)->nullable()->after('url');
        });
    }

    public function down(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            $table->dropColumn('epg_url');
        });
    }
};
