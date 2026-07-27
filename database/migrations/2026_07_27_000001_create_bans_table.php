<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bans', function (Blueprint $table) {
            $table->id();
            // Keyed on email so a ban SURVIVES the user account being deleted and blocks
            // re-registration/login with the same address. Lower-cased on write (see the Ban model).
            $table->string('email')->unique();
            $table->string('reason')->nullable();
            // Who banned them; nulled (not cascaded) if that admin is later deleted so the record stays.
            $table->foreignId('banned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bans');
    }
};
