<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_with_valid_credentials(): void
    {
        $company = $this->createCompany();
        $user = User::factory()->create([
            'company_id' => $company->id,
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'user' => ['id', 'name', 'email', 'role', 'company'],
                'token',
            ])
            ->assertJson([
                'user' => [
                    'email' => 'test@example.com',
                ],
            ]);
    }

    public function test_user_cannot_login_with_invalid_credentials(): void
    {
        $company = $this->createCompany();
        User::factory()->create([
            'company_id' => $company->id,
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_user_cannot_login_with_inactive_account(): void
    {
        $company = $this->createCompany();
        User::factory()->create([
            'company_id' => $company->id,
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
            'status' => 'inactive',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_user_can_logout(): void
    {
        $user = $this->createUser();
        $this->actingAsUser($user);

        $response = $this->postJson('/api/auth/logout');

        $response->assertStatus(200)
            ->assertJson(['message' => 'Logged out successfully']);
    }

    public function test_user_can_get_current_user(): void
    {
        $user = $this->createUser();
        $this->actingAsUser($user);

        $response = $this->getJson('/api/auth/me');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'user' => ['id', 'name', 'email', 'role', 'company'],
                'permissions',
                'is_super_admin',
            ])
            ->assertJson([
                'user' => [
                    'id' => $user->id,
                    'email' => $user->email,
                ],
            ]);
    }

    public function test_unauthenticated_user_cannot_access_protected_routes(): void
    {
        $response = $this->getJson('/api/auth/me');

        $response->assertStatus(401);
    }

    public function test_login_requires_email_and_password(): void
    {
        $response = $this->postJson('/api/auth/login', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }
}

