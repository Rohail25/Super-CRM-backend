<?php

namespace Database\Factories;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SubscriptionPlan>
 */
class SubscriptionPlanFactory extends Factory
{
    protected $model = SubscriptionPlan::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->words(2, true) . ' Plan',
            'description' => $this->faker->sentence(),
            'amount' => $this->faker->numberBetween(1000, 10000), // €10.00 to €100.00
            'currency' => 'eur',
            'interval' => $this->faker->randomElement(['month', 'year']),
            'features' => [
                'Feature 1',
                'Feature 2',
                'Feature 3',
            ],
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the plan is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
