<?php

namespace Database\Factories;

use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Project>
 */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->words(2, true);
        $slug = Str::slug($name);

        return [
            'name' => ucwords($name),
            'slug' => $slug,
            'description' => fake()->optional()->sentence(),
            'integration_type' => fake()->randomElement(['api', 'iframe', 'hybrid']),
            'api_base_url' => fake()->optional()->url(),
            'api_auth_type' => 'bearer',
            'api_key' => fake()->optional()->sha256(),
            'api_secret' => fake()->optional()->sha256(),
            'api_signup_endpoint' => fake()->optional()->lexify('/api/v1/?????'),
            'api_login_endpoint' => fake()->optional()->lexify('/api/v1/?????'),
            'api_sso_endpoint' => fake()->optional()->lexify('/api/v1/?????'),
            'admin_panel_url' => fake()->optional()->url(),
            'iframe_width' => '100%',
            'iframe_height' => '100vh',
            'iframe_sandbox' => 'allow-same-origin allow-scripts',
            'sso_enabled' => true,
            'sso_method' => 'jwt',
            'sso_token_expiry' => 3600,
            'sso_redirect_url' => fake()->optional()->url(),
            'sso_callback_url' => fake()->optional()->url(),
            'requires_password_storage' => false,
            'is_legacy' => false,
            'driver_class' => null,
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the project uses API integration.
     */
    public function api(): static
    {
        return $this->state(fn (array $attributes) => [
            'integration_type' => 'api',
            'api_base_url' => fake()->url(),
        ]);
    }

    /**
     * Indicate that the project uses iframe integration.
     */
    public function iframe(): static
    {
        return $this->state(fn (array $attributes) => [
            'integration_type' => 'iframe',
            'admin_panel_url' => fake()->url(),
        ]);
    }

    /**
     * Indicate that the project uses hybrid integration.
     */
    public function hybrid(): static
    {
        return $this->state(fn (array $attributes) => [
            'integration_type' => 'hybrid',
            'api_base_url' => fake()->url(),
            'admin_panel_url' => fake()->url(),
        ]);
    }

    /**
     * Indicate that the project is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}

