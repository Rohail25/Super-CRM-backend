<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Company;
use Tests\TestCase;

class CustomerTest extends TestCase
{
    public function test_user_can_list_customers(): void
    {
        $company = $this->createCompany();
        $user = $this->createUser($company);

        Customer::factory()->count(5)->create(['company_id' => $company->id]);

        $this->actingAsUser($user);

        $response = $this->getJson('/api/customers');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'first_name', 'last_name', 'email', 'phone', 'company_id'],
                ],
                'current_page',
                'per_page',
                'total',
            ]);
    }

    public function test_user_can_create_customer(): void
    {
        $company = $this->createCompany();
        $user = $this->createUser($company);

        $this->actingAsUser($user);

        $response = $this->postJson('/api/customers', [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
            'phone' => '+1234567890',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'id',
                'first_name',
                'last_name',
                'email',
                'phone',
                'company_id',
            ])
            ->assertJson([
                'first_name' => 'John',
                'last_name' => 'Doe',
                'email' => 'john.doe@example.com',
            ]);

        $this->assertDatabaseHas('customers', [
            'email' => 'john.doe@example.com',
            'company_id' => $company->id,
        ]);
    }

    public function test_user_can_update_customer(): void
    {
        $company = $this->createCompany();
        $user = $this->createUser($company);
        $customer = Customer::factory()->create(['company_id' => $company->id]);

        $this->actingAsUser($user);

        $response = $this->putJson("/api/customers/{$customer->id}", [
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => $customer->email,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'first_name' => 'Jane',
                'last_name' => 'Smith',
            ]);

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'first_name' => 'Jane',
            'last_name' => 'Smith',
        ]);
    }

    public function test_user_can_view_customer(): void
    {
        $company = $this->createCompany();
        $user = $this->createUser($company);
        $customer = Customer::factory()->create(['company_id' => $company->id]);

        $this->actingAsUser($user);

        $response = $this->getJson("/api/customers/{$customer->id}");

        $response->assertStatus(200)
            ->assertJson([
                'id' => $customer->id,
                'email' => $customer->email,
            ]);
    }

    public function test_user_can_search_customers(): void
    {
        $company = $this->createCompany();
        $user = $this->createUser($company);

        Customer::factory()->create([
            'company_id' => $company->id,
            'first_name' => 'John',
            'email' => 'john@example.com',
        ]);

        Customer::factory()->create([
            'company_id' => $company->id,
            'first_name' => 'Jane',
            'email' => 'jane@example.com',
        ]);

        $this->actingAsUser($user);

        $response = $this->getJson('/api/customers?search=john');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('john@example.com', $data[0]['email']);
    }

    public function test_customer_creation_requires_email(): void
    {
        $company = $this->createCompany();
        $user = $this->createUser($company);

        $this->actingAsUser($user);

        $response = $this->postJson('/api/customers', [
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_customer_email_must_be_unique(): void
    {
        $company = $this->createCompany();
        $user = $this->createUser($company);

        $existingCustomer = Customer::factory()->create([
            'company_id' => $company->id,
            'email' => 'existing@example.com',
        ]);

        $this->actingAsUser($user);

        $response = $this->postJson('/api/customers', [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'existing@example.com',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }
}

