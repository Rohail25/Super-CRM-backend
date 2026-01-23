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
        Schema::table('campaigns', function (Blueprint $table) {
            $table->string('payment_status')->nullable()->after('budget')->default('unpaid'); // unpaid, pending, paid, failed
            $table->string('stripe_payment_intent_id')->nullable()->after('payment_status');
            $table->string('stripe_checkout_session_id')->nullable()->after('stripe_payment_intent_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn(['payment_status', 'stripe_payment_intent_id', 'stripe_checkout_session_id']);
        });
    }
};
