<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Unify the admin-blocked account status on "banned" (previously "disabled").
     */
    public function up(): void
    {
        DB::table('users')->where('status', 'disabled')->update(['status' => 'banned']);
    }

    public function down(): void
    {
        DB::table('users')->where('status', 'banned')->update(['status' => 'disabled']);
    }
};
