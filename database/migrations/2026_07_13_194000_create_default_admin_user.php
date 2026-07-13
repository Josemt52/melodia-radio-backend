<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->updateOrInsert(
            ['username' => 'admin'],
            [
                'name' => 'admin',
                'email' => 'admin@example.local',
                'password' => bcrypt('admin'),
                'role' => 'admin',
                'is_active' => true,
                'email_verified_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('users')
            ->where('username', 'admin')
            ->where('email', 'admin@example.local')
            ->delete();
    }
};
