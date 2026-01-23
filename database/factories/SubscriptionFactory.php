<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Subscription>
 */
class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startDate = now();
        $endDate = $startDate->copy()->addMonth();

        return [
            'company_id' => Company::factory(),
            'subscription_plan_id' => SubscriptionPlan::factory(),
            'stripe_customer_id' => 'cus_' . $this->faker->uuid(),
            'stripe_payment_intent_id' => 'pi_' . $this->faker->uuid(),
            'status' => 'active',
            'current_period_start' => $startDate,
            'current_period_end' => $endDate,
            'cancel_at_period_end' => false,
            'canceled_at' => null,
            'trial_ends_at' => null,
        ];
    }

    /**
     * Indicate that the subscription is canceled.
     */
    public function canceled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'canceled',
            'canceled_at' => now(),
        ]);
    }

    /**
     * Indicate that the subscription is past due.
     */
    public function pastDue(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'past_due',
        ]);
    }

    /**
     * Indicate that the subscription will cancel at period end.
     */
    public function cancelingAtPeriodEnd(): static
    {
        return $this->state(fn (array $attributes) => [
            'cancel_at_period_end' => true,
        ]);
    }
}
