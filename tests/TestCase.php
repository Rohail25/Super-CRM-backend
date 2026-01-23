<?php

namespace Tests;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * Create a super admin user.
     */
    protected function createSuperAdmin(array $attributes = []): User
    {
        $company = Company::factory()->create(['status' => 'active']);
        
        return User::factory()->create(array_merge([
            'company_id' => $company->id,
            'role' => 'super_admin',
            'status' => 'active',
        ], $attributes));
    }

    /**
     * Create a company admin user.
     */
    protected function createCompanyAdmin(Company $company = null, array $attributes = []): User
    {
        if (!$company) {
            $company = Company::factory()->create(['status' => 'active']);
        }

        return User::factory()->create(array_merge([
            'company_id' => $company->id,
            'role' => 'company_admin',
            'status' => 'active',
        ], $attributes));
    }

    /**
     * Create a regular user.
     */
    protected function createUser(Company $company = null, array $attributes = []): User
    {
        if (!$company) {
            $company = Company::factory()->create(['status' => 'active']);
        }

        return User::factory()->create(array_merge([
            'company_id' => $company->id,
            'role' => 'staff',
            'status' => 'active',
        ], $attributes));
    }

    /**
     * Create an active company.
     */
    protected function createCompany(array $attributes = []): Company
    {
        return Company::factory()->create(array_merge([
            'status' => 'active',
        ], $attributes));
    }

    /**
     * Authenticate as a user.
     */
    protected function actingAsUser(User $user): self
    {
        Sanctum::actingAs($user, ['*']);
        return $this;
    }

    /**
     * Authenticate as a super admin.
     */
    protected function actingAsSuperAdmin(array $attributes = []): self
    {
        $user = $this->createSuperAdmin($attributes);
        return $this->actingAsUser($user);
    }

    /**
     * Authenticate as a company admin.
     */
    protected function actingAsCompanyAdmin(Company $company = null, array $attributes = []): self
    {
        $user = $this->createCompanyAdmin($company, $attributes);
        return $this->actingAsUser($user);
    }
}
