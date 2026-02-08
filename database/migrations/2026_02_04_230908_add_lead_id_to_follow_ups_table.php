<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('follow_ups', function (Blueprint $table) {
            // Make customer_id nullable (since we can have leads instead)
            $table->foreignId('customer_id')->nullable()->change();
            
            // Add lead_id column
            $table->foreignId('lead_id')->nullable()->after('customer_id')->constrained('leads')->onDelete('cascade');
            
            // Add index for lead_id
            $table->index('lead_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('follow_ups', function (Blueprint $table) {
            $table->dropForeign(['lead_id']);
            $table->dropIndex(['lead_id']);
            $table->dropColumn('lead_id');
            
            // Revert customer_id to not nullable (if needed)
            // Note: This might fail if there are null values
            // $table->foreignId('customer_id')->nullable(false)->change();
        });
    }
};
