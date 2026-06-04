<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feed_logs', function (Blueprint $table) {
            $table->id();
            $table->string('msgid', 16)->index();   // ties a line to one update run
            $table->foreignId('provider_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('level', 8)->default('info'); // info | warn | error
            $table->text('message');
            $table->timestamps();

            $table->index(['msgid', 'id']); // ordered retrieval per run
            $table->index('created_at');    // for weekly trim
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_logs');
    }
};
