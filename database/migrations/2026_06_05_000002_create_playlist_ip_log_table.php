<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('playlist_ip_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('playlist_id')->index();
            $table->string('ip', 45);
            $table->timestamp('last_seen')->useCurrent();
            $table->unique(['playlist_id', 'ip']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playlist_ip_log');
    }
};
