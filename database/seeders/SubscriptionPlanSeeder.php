<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

class SubscriptionPlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create default subscription plan
        // Note: stripe_price_id should be updated after creating the plan in Stripe Dashboard
        SubscriptionPlan::firstOrCreate(
            [
                'stripe_price_id' => 'price_placeholder', // Update this with actual Stripe Price ID
            ],
            [
                'name' => 'Standard Plan',
                'description' => 'Full access to LEO24 CRM platform with all features',
                'amount' => 9900, // €99.00 in cents
                'currency' => 'eur',
                'interval' => 'month',
                'features' => [
                    'Unlimited customers',
                    'Unlimited opportunities',
                    'Task management',
                    'Document management',
                    'Advanced reporting',
                    'Webhook integrations',
                    'Custom fields',
                    'Email support',
                ],
                'is_active' => true,
            ]
        );
    }
}

