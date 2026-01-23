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
        Schema::table('subscriptions', function (Blueprint $table) {
            // Check if column exists before trying to modify it
            if (Schema::hasColumn('subscriptions', 'stripe_subscription_id')) {
                $table->string('stripe_subscription_id')->nullable()->change();
            } else {
                // If column doesn't exist, add it as nullable
                $table->string('stripe_subscription_id')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('stripe_subscription_id')->nullable(false)->change();
        });
    }
};
