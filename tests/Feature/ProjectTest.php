<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Project;
use App\Models\CompanyProjectAccess;
use Tests\TestCase;

class ProjectTest extends TestCase
{
    public function test_user_can_list_accessible_projects(): void
    {
        $company = $this->createCompany();
        $user = $this->createUser($company);

        $project1 = Project::factory()->create(['name' => 'Project 1']);
        $project2 = Project::factory()->create(['name' => 'Project 2']);

        // Grant access to project1 only
        CompanyProjectAccess::factory()->create([
            'company_id' => $company->id,
            'project_id' => $project1->id,
            'status' => 'active',
        ]);

        $this->actingAsUser($user);

        $response = $this->getJson('/api/projects');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('Project 1', $data[0]['name']);
    }

    public function test_super_admin_can_see_all_projects(): void
    {
        Project::factory()->count(3)->create();

        $superAdmin = $this->createSuperAdmin();
        $this->actingAsUser($superAdmin);

        $response = $this->getJson('/api/projects');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(3, $data);
    }

    public function test_user_can_view_accessible_project(): void
    {
        $company = $this->createCompany();
        $user = $this->createUser($company);
        $project = Project::factory()->create();

        CompanyProjectAccess::factory()->create([
            'company_id' => $company->id,
            'project_id' => $project->id,
            'status' => 'active',
        ]);

        $this->actingAsUser($user);

        $response = $this->getJson("/api/projects/{$project->id}");

        $response->assertStatus(200)
            ->assertJson([
                'id' => $project->id,
                'name' => $project->name,
            ]);
    }

    public function test_user_cannot_view_inaccessible_project(): void
    {
        $company = $this->createCompany();
        $user = $this->createUser($company);
        $project = Project::factory()->create();

        $this->actingAsUser($user);

        $response = $this->getJson("/api/projects/{$project->id}");

        $response->assertStatus(403);
    }

    public function test_super_admin_can_create_project(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $this->actingAsUser($superAdmin);

        $response = $this->postJson('/api/projects', [
            'name' => 'New Project',
            'slug' => 'new-project',
            'description' => 'Test project',
            'integration_type' => 'api',
            'is_active' => true,
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'name' => 'New Project',
                'slug' => 'new-project',
            ]);

        $this->assertDatabaseHas('projects', [
            'name' => 'New Project',
            'slug' => 'new-project',
        ]);
    }

    public function test_regular_user_cannot_create_project(): void
    {
        $user = $this->createUser();
        $this->actingAsUser($user);

        $response = $this->postJson('/api/projects', [
            'name' => 'New Project',
            'slug' => 'new-project',
        ]);

        $response->assertStatus(403);
    }
}

