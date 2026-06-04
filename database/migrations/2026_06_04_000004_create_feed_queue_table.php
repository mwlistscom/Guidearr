<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feed_queue', function (Blueprint $table) {
            $table->id();
            $table->string('msgid', 16)->unique();              // identifies one update run
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_id')->unique()->constrained()->cascadeOnDelete(); // one row per provider
            $table->string('type', 16);                          // xtream | m3u | xmltv | manual
            $table->string('state', 16)->default('queued');      // queued | running | done | error
            $table->string('processor', 128)->nullable();        // host/worker that claimed it
            $table->timestamp('dstart')->nullable();
            $table->timestamp('dstop')->nullable();
            $table->unsignedInteger('elapsed')->default(0);      // seconds
            $table->unsignedTinyInteger('hour')->default(0);     // scheduled hour
            $table->unsignedInteger('error')->default(0);
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamps();

            $table->index('state');
            $table->index('user_id');
            $table->index('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_queue');
    }
};
