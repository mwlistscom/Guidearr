<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Registry only — the heavy channel/group pointers live in a per-playlist SQLite file.
        Schema::create('playlists', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('name')->default('Playlist');
            $t->string('iplock')->nullable();
            $t->string('cipher', 32)->unique();          // URL key for m3u/tvg/strm
            $t->unsignedInteger('channel_start')->default(100);
            $t->boolean('extgrp_tags')->default(true);
            $t->unsignedBigInteger('guide_provider_id')->nullable(); // single EPG source
            $t->boolean('enabled')->default(true);
            $t->timestamp('last_touch_at')->nullable();
            $t->timestamps();
        });

        Schema::create('playlist_providers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('playlist_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('provider_id');
            $t->timestamps();
            $t->unique(['playlist_id', 'provider_id']);
            $t->index('provider_id'); // fast reverse lookup for refresh-time reconcile
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playlist_providers');
        Schema::dropIfExists('playlists');
    }
};
