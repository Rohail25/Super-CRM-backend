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
        // Add image_path column
        Schema::table('campaigns', function (Blueprint $table) {
            $table->string('image_path')->nullable()->after('description');
        });

        // First, temporarily change the type column to VARCHAR to allow any value
        DB::statement("ALTER TABLE campaigns MODIFY COLUMN type VARCHAR(255)");

        // Update all existing records to use a valid new type value
        // Convert all old type values (email, sms, social_media, etc.) to 'BANNER_TOP' as default
        $oldTypes = ['email', 'sms', 'social_media', 'advertising', 'content', 'event', 'other'];
        foreach ($oldTypes as $oldType) {
            DB::table('campaigns')->where('type', $oldType)->update(['type' => 'BANNER_TOP']);
        }

        // Also update any records that might have invalid values
        DB::table('campaigns')
            ->whereNotIn('type', ['BANNER_TOP', 'BANNER_SIDE', 'INLINE', 'FOOTER', 'SLIDER', 'TICKER', 'POPUP', 'STICKY'])
            ->update(['type' => 'BANNER_TOP']);

        // Now change the column back to ENUM with the new values
        DB::statement("ALTER TABLE campaigns MODIFY COLUMN type ENUM('BANNER_TOP', 'BANNER_SIDE', 'INLINE', 'FOOTER', 'SLIDER', 'TICKER', 'POPUP', 'STICKY') DEFAULT 'BANNER_TOP'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // First, temporarily change the type column to VARCHAR to allow any value
        DB::statement("ALTER TABLE campaigns MODIFY COLUMN type VARCHAR(255)");

        // Update all existing records to use a valid old type value
        // Convert all new type values to 'email' as default
        $newTypes = ['BANNER_TOP', 'BANNER_SIDE', 'INLINE', 'FOOTER', 'SLIDER', 'TICKER', 'POPUP', 'STICKY'];
        foreach ($newTypes as $newType) {
            DB::table('campaigns')->where('type', $newType)->update(['type' => 'email']);
        }

        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn('image_path');
        });

        // Revert type enum to original values
        DB::statement("ALTER TABLE campaigns MODIFY COLUMN type ENUM('email', 'sms', 'social_media', 'advertising', 'content', 'event', 'other') DEFAULT 'email'");
    }
};
