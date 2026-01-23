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
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('subscription_plan_id')->constrained('subscription_plans')->onDelete('restrict');
            
            // Stripe IDs (only customer ID, payment intent ID for tracking payments)
            $table->string('stripe_customer_id')->nullable();
            $table->string('stripe_payment_intent_id')->nullable(); // Track payment for current period
            
            // Subscription status
            $table->enum('status', ['active', 'trialing', 'past_due', 'canceled', 'unpaid', 'incomplete', 'incomplete_expired'])->default('active');
            
            // Billing periods
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            
            // Cancellation
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('canceled_at')->nullable();
            
            // Trial
            $table->timestamp('trial_ends_at')->nullable();
            
            $table->timestamps();
            
            $table->index('company_id');
            $table->index('status');
            $table->index('stripe_customer_id');
            $table->index('current_period_end');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};

