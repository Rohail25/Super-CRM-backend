<?php

namespace Tests\Feature;

use App\Models\Company;
use Tests\TestCase;

class CompanyTest extends TestCase
{
    public function test_super_admin_can_list_companies(): void
    {
        Company::factory()->count(3)->create();

        $superAdmin = $this->createSuperAdmin();
        $this->actingAsUser($superAdmin);

        $response = $this->getJson('/api/companies');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'vat', 'status', 'created_at'],
                ],
            ]);
    }

    public function test_regular_user_cannot_list_companies(): void
    {
        $user = $this->createUser();
        $this->actingAsUser($user);

        $response = $this->getJson('/api/companies');

        $response->assertStatus(403);
    }

    public function test_super_admin_can_create_company(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $this->actingAsUser($superAdmin);

        $response = $this->postJson('/api/companies', [
            'name' => 'Test Company',
            'vat' => 'IT12345678901',
            'address' => '123 Test Street',
            'status' => 'active',
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'name' => 'Test Company',
                'vat' => 'IT12345678901',
                'status' => 'active',
            ]);

        $this->assertDatabaseHas('companies', [
            'name' => 'Test Company',
            'vat' => 'IT12345678901',
        ]);
    }

    public function test_super_admin_can_update_company(): void
    {
        $company = $this->createCompany();
        $superAdmin = $this->createSuperAdmin();
        $this->actingAsUser($superAdmin);

        $response = $this->putJson("/api/companies/{$company->id}", [
            'name' => 'Updated Company Name',
            'vat' => $company->vat,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'name' => 'Updated Company Name',
            ]);

        $this->assertDatabaseHas('companies', [
            'id' => $company->id,
            'name' => 'Updated Company Name',
        ]);
    }

    public function test_super_admin_can_delete_company(): void
    {
        $company = $this->createCompany();
        $superAdmin = $this->createSuperAdmin();
        $this->actingAsUser($superAdmin);

        $response = $this->deleteJson("/api/companies/{$company->id}");

        $response->assertStatus(200);

        $this->assertSoftDeleted('companies', [
            'id' => $company->id,
        ]);
    }

    public function test_company_creation_requires_name(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $this->actingAsUser($superAdmin);

        $response = $this->postJson('/api/companies', [
            'vat' => 'IT12345678901',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }
}

