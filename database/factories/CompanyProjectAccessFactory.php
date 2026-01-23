<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\CompanyProjectAccess;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CompanyProjectAccess>
 */
class CompanyProjectAccessFactory extends Factory
{
    protected $model = CompanyProjectAccess::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'project_id' => Project::factory(),
            'api_credentials' => null,
            'external_company_id' => fake()->optional()->uuid(),
            'external_account_data' => null,
            'status' => 'active',
            'approved_at' => now(),
            'approved_by' => User::factory(),
            'signup_request_data' => null,
            'last_sync_at' => null,
            'last_error' => null,
            'retry_count' => 0,
            'rate_limit_per_minute' => 60,
            'rate_limit_per_hour' => 1000,
            'circuit_breaker_state' => 'closed',
            'circuit_breaker_failures' => 0,
            'circuit_breaker_reset_at' => null,
        ];
    }

    /**
     * Indicate that the access is pending.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
            'approved_at' => null,
            'approved_by' => null,
        ]);
    }

    /**
     * Indicate that the access is suspended.
     */
    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'suspended',
        ]);
    }

    /**
     * Indicate that the access partially failed.
     */
    public function partialFailed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'partial_failed',
            'last_error' => fake()->sentence(),
        ]);
    }
}

