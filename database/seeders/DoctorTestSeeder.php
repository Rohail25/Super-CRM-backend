<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\CompanyProjectAccess;
use App\Models\CompanyProjectUser;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DoctorTestSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Creating test data for doctor project...');

        // 1. Create or get the doctor project
        $project = Project::firstOrCreate(
            ['slug' => 'mydoctor'],
            [
                'name' => 'MyDoctor+',
                'slug' => 'mydoctor',
                'description' => 'Medical platform',
                'integration_type' => 'api',
                'api_base_url' => 'https://mydoctoradmin.mydoctorplus.it',
                'api_auth_type' => 'bearer',
                'is_active' => true,
            ]
        );

        $this->command->info("Project created/found: {$project->name} (ID: {$project->id})");

        // 2. Create test company
        $company = Company::firstOrCreate(
            ['vat' => 'TEST123456789'],
            [
                'name' => 'Test Doctor Company',
                'vat' => 'TEST123456789',
                'address' => 'Test Address, Test City',
                'status' => 'active',
            ]
        );

        $this->command->info("Company created/found: {$company->name} (ID: {$company->id})");

        // 3. Create test user with plain password
        $password = '11221122';
        $user = User::firstOrCreate(
            ['email' => 'sohaib@gmail.com'],
            [
                'name' => 'Sohaib Ahmad',
                'email' => 'sohaib@gmail.com',
                'password' => Hash::make($password),
                'role' => 'company_admin',
                'company_id' => $company->id,
                'status' => 'active',
            ]
        );

        // Store plain password (encrypted) for external API use
        if ($user->wasRecentlyCreated || !$user->plain_password) {
            $user->setPlainPassword($password);
            $user->save();
            $this->command->info("User created with plain password stored");
        } else {
            $this->command->info("User already exists, updating plain password");
            $user->setPlainPassword($password);
            $user->save();
        }

        $this->command->info("User created/found: {$user->name} ({$user->email}) (ID: {$user->id})");

        // 4. Create company_project_access
        $access = CompanyProjectAccess::firstOrCreate(
            [
                'company_id' => $company->id,
                'project_id' => $project->id,
            ],
            [
                'status' => 'active',
                'approved_at' => now(),
                'approved_by' => $user->id,
            ]
        );

        $this->command->info("Company project access created/found (ID: {$access->id})");

        // 5. Create company_project_users
        $projectUser = CompanyProjectUser::firstOrCreate(
            [
                'company_project_access_id' => $access->id,
                'user_id' => $user->id,
            ],
            [
                'status' => 'active',
                'external_username' => $user->email,
            ]
        );

        $this->command->info("Company project user created/found (ID: {$projectUser->id})");

        $this->command->info('');
        $this->command->info('✅ Doctor test data created successfully!');
        $this->command->info('');
        $this->command->info('Test Account:');
        $this->command->info("  Email: sohaib@gmail.com");
        $this->command->info("  Password: 11221122");
        $this->command->info("  Role: company_admin");
        $this->command->info("  Company: {$company->name}");
        $this->command->info("  Project: {$project->name}");
        $this->command->info('');
    }
}
