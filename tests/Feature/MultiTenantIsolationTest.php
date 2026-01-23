<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use Tests\TestCase;

class MultiTenantIsolationTest extends TestCase
{
    public function test_user_can_only_see_own_company_customers(): void
    {
        $company1 = $this->createCompany(['name' => 'Company 1']);
        $company2 = $this->createCompany(['name' => 'Company 2']);

        $user1 = $this->createUser($company1);
        $user2 = $this->createUser($company2);

        // Create customers for each company
        Customer::factory()->create([
            'company_id' => $company1->id,
            'email' => 'customer1@example.com',
        ]);

        Customer::factory()->create([
            'company_id' => $company2->id,
            'email' => 'customer2@example.com',
        ]);

        // User 1 should only see Company 1's customers
        $this->actingAsUser($user1);
        $response = $this->getJson('/api/customers');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('customer1@example.com', $data[0]['email']);

        // User 2 should only see Company 2's customers
        $this->actingAsUser($user2);
        $response = $this->getJson('/api/customers');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('customer2@example.com', $data[0]['email']);
    }

    public function test_user_cannot_access_other_company_customers(): void
    {
        $company1 = $this->createCompany();
        $company2 = $this->createCompany();

        $user1 = $this->createUser($company1);
        $customer2 = Customer::factory()->create([
            'company_id' => $company2->id,
        ]);

        $this->actingAsUser($user1);

        $response = $this->getJson("/api/customers/{$customer2->id}");

        $response->assertStatus(403);
    }

    public function test_super_admin_can_access_all_companies(): void
    {
        $company1 = $this->createCompany();
        $company2 = $this->createCompany();

        Customer::factory()->create(['company_id' => $company1->id]);
        Customer::factory()->create(['company_id' => $company2->id]);

        $superAdmin = $this->createSuperAdmin();

        $this->actingAsUser($superAdmin);

        $response = $this->getJson('/api/customers');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(2, $data);
    }

    public function test_super_admin_can_filter_by_company_id(): void
    {
        $company1 = $this->createCompany();
        $company2 = $this->createCompany();

        Customer::factory()->create(['company_id' => $company1->id]);
        Customer::factory()->create(['company_id' => $company2->id]);

        $superAdmin = $this->createSuperAdmin();

        $this->actingAsUser($superAdmin);

        $response = $this->getJson("/api/customers?company_id={$company1->id}");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals($company1->id, $data[0]['company_id']);
    }

    public function test_user_cannot_create_customer_for_other_company(): void
    {
        $company1 = $this->createCompany();
        $company2 = $this->createCompany();

        $user1 = $this->createUser($company1);

        $this->actingAsUser($user1);

        $response = $this->postJson('/api/customers', [
            'first_name' => 'Test',
            'last_name' => 'Customer',
            'email' => 'test@example.com',
            'company_id' => $company2->id, // Trying to set different company
        ]);

        // Should be scoped to user's company regardless of input
        $response->assertStatus(201);
        $customer = Customer::where('email', 'test@example.com')->first();
        $this->assertEquals($company1->id, $customer->company_id);
    }
}

