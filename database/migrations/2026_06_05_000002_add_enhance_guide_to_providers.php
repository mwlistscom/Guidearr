<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            // Default TRUE so existing providers get guide enhancement on their next refresh.
            $table->boolean('enhance_guide')->default(true)->after('enabled');
        });
    }

    public function down(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            $table->dropColumn('enhance_guide');
        });
    }
};
