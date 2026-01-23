<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // MySQL doesn't support ALTER ENUM directly, so we need to use raw SQL
        DB::statement("ALTER TABLE `users` MODIFY COLUMN `status` ENUM('active', 'inactive', 'suspended', 'pending') DEFAULT 'active'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Before removing 'pending', update any pending users to 'inactive'
        DB::table('users')->where('status', 'pending')->update(['status' => 'inactive']);
        
        // Remove 'pending' from enum
        DB::statement("ALTER TABLE `users` MODIFY COLUMN `status` ENUM('active', 'inactive', 'suspended') DEFAULT 'active'");
    }
};
