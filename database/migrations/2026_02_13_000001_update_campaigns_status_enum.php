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
        // First, change the ENUM column to VARCHAR temporarily to allow any value
        DB::statement("ALTER TABLE campaigns MODIFY COLUMN status VARCHAR(255)");

        // Then, convert old status values to new ones
        DB::table('campaigns')->where('status', 'draft')->update(['status' => 'pending']);
        DB::table('campaigns')->where('status', 'scheduled')->update(['status' => 'pending']);
        DB::table('campaigns')->where('status', 'completed')->update(['status' => 'expired']);
        DB::table('campaigns')->where('status', 'cancelled')->update(['status' => 'rejected']);

        // Finally, change it back to ENUM with new values and set default to 'pending'
        DB::statement("ALTER TABLE campaigns MODIFY COLUMN status ENUM('pending', 'active', 'paused', 'expired', 'rejected') DEFAULT 'pending'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // First, change the ENUM column to VARCHAR temporarily to allow any value
        DB::statement("ALTER TABLE campaigns MODIFY COLUMN status VARCHAR(255)");

        // Then, convert new status values back to old ones
        DB::table('campaigns')->where('status', 'pending')->update(['status' => 'draft']);
        DB::table('campaigns')->where('status', 'expired')->update(['status' => 'completed']);
        DB::table('campaigns')->where('status', 'rejected')->update(['status' => 'cancelled']);

        // Finally, change it back to ENUM with old values
        DB::statement("ALTER TABLE campaigns MODIFY COLUMN status ENUM('draft', 'scheduled', 'active', 'paused', 'completed', 'cancelled') DEFAULT 'draft'");
    }
};
