<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purge_queue', function (Blueprint $table) {
            $table->id();
            // No FK: the user (and their providers) are gone by the time this is processed.
            $table->unsignedBigInteger('user_id')->index();
            $table->string('email')->nullable();
            $table->json('payload');                      // [{id, path, name}, ...] of removed provider stores
            $table->string('state', 16)->default('queued'); // queued | running | done | error
            $table->unsignedInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purge_queue');
    }
};
