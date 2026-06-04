<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_refresh_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('status', 16)->default('ok'); // ok | failed
            $table->text('message')->nullable();
            $table->unsignedBigInteger('bytes')->default(0);
            $table->timestamps();

            $table->index('provider_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_refresh_logs');
    }
};
