<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 128);
            $table->string('type', 16)->default('m3u'); // xtream | m3u | xmltv | manual
            $table->string('url', 1024)->nullable();
            $table->string('username')->nullable();
            $table->text('password')->nullable();        // encrypted at rest
            $table->string('timeshift', 16)->nullable(); // captured from Xtream server_info
            $table->smallInteger('myshift')->default(0); // additional EPG hour shift (signed)
            $table->boolean('enabled')->default(false);
            $table->unsignedTinyInteger('refresh_hour')->default(2); // auto-refresh hour (1-3 set on create)
            $table->timestamp('last_refresh_at')->nullable();
            $table->string('last_status', 16)->default('never'); // never | ok | failed
            $table->timestamp('last_touch_at')->nullable();       // last user action of any kind
            $table->timestamps();

            $table->index(['user_id', 'enabled']);
            $table->index('type');
            $table->index('name');
            $table->index('last_touch_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('providers');
    }
};
