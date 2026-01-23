<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Create super admin user
        User::firstOrCreate(
            ['email' => 'admin@leo24.com'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('password'),
                'role' => 'super_admin',
                'status' => 'active',
                'company_id' => null, // Super admin has no company
            ]
        );

        $this->command->info('Super admin user created:');
        $this->command->info('Email: admin@leo24.com');
        $this->command->info('Password: password');

        // Seed in order (companies first, then users, then projects, then customers)
        $this->call(CompanySeeder::class);
        $this->call(ProjectSeeder::class);
        $this->call(SubscriptionPlanSeeder::class);
        $this->call(UserSeeder::class);
        $this->call(CustomerSeeder::class);
        
        // Create test data for doctor project (optional - for testing)
        $this->call(DoctorTestSeeder::class);

        $this->command->info('');
        $this->command->info('✅ Database seeded successfully!');
        $this->command->info('');
        $this->command->info('Test Accounts:');
        $this->command->info('  Super Admin: admin@leo24.com / password');
        $this->command->info('  Company Admin: admin@alpha.com / password');
        $this->command->info('  Manager: manager@alpha.com / password');
        $this->command->info('  Staff: staff@alpha.com / password');
        $this->command->info('  Doctor Test User: sohaib@gmail.com / 11221122');
    }
}
