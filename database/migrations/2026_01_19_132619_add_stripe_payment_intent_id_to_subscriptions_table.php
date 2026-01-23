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
        Schema::table('subscriptions', function (Blueprint $table) {
            // Check if column exists before adding it
            $columnExists = Schema::hasColumn('subscriptions', 'stripe_payment_intent_id');
            if (!$columnExists) {
                $table->string('stripe_payment_intent_id')->nullable()->after('stripe_customer_id');
            }
        });

        // Check if index exists before adding it
        $indexes = DB::select("SHOW INDEX FROM subscriptions WHERE Column_name = 'stripe_payment_intent_id'");
        if (empty($indexes)) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->index('stripe_payment_intent_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex(['stripe_payment_intent_id']);
            $table->dropColumn('stripe_payment_intent_id');
        });
    }
};
