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
        // First, modify the status column to allow 'approved'
        // MySQL requires dropping and recreating enum columns
        DB::statement("ALTER TABLE companies MODIFY COLUMN status ENUM('pending', 'approved', 'active', 'suspended') DEFAULT 'pending'");
        
        Schema::table('companies', function (Blueprint $table) {
            $table->enum('subscription_status', ['none', 'approved', 'active', 'past_due', 'canceled'])->default('none')->after('status');
            $table->string('stripe_customer_id')->nullable()->after('subscription_status');
            
            $table->index('subscription_status');
            $table->index('stripe_customer_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropIndex(['subscription_status']);
            $table->dropIndex(['stripe_customer_id']);
            $table->dropColumn(['subscription_status', 'stripe_customer_id']);
        });
        
        // Revert status enum
        DB::statement("ALTER TABLE companies MODIFY COLUMN status ENUM('pending', 'active', 'suspended') DEFAULT 'pending'");
    }
};

