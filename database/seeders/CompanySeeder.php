<?php

namespace Database\Seeders;

use App\Models\Company;
use Illuminate\Database\Seeder;

class CompanySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $companies = [
            [
                'name' => 'Alpha SRL',
                'vat' => 'IT12345678901',
                'address' => 'Via Roma 1, Milano, 20100',
                'status' => 'active',
                'settings' => [
                    'sector' => 'retail',
                    'modules' => ['crm_marketing', 'call_center'],
                ],
            ],
            [
                'name' => 'Beta Medical Group',
                'vat' => 'IT98765432109',
                'address' => 'Via Garibaldi 10, Roma, 00100',
                'status' => 'active',
                'settings' => [
                    'sector' => 'medical',
                    'modules' => ['crm_assistance', 'inventory'],
                ],
            ],
            [
                'name' => 'Gamma Real Estate',
                'vat' => 'IT11223344556',
                'address' => 'Corso Vittorio Emanuele 5, Torino, 10100',
                'status' => 'pending',
                'settings' => [
                    'sector' => 'real_estate',
                    'modules' => ['crm_marketing'],
                ],
            ],
        ];

        foreach ($companies as $company) {
            Company::firstOrCreate(
                ['vat' => $company['vat']],
                $company
            );
        }

        $this->command->info('Sample companies created: ' . count($companies));
    }
}
