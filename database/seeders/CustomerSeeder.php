<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Customer;
use Illuminate\Database\Seeder;

class CustomerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $alpha = Company::where('vat', 'IT12345678901')->first();
        $beta = Company::where('vat', 'IT98765432109')->first();

        $customers = [
            [
                'company_id' => $alpha?->id,
                'email' => 'mario.rossi@example.com',
                'phone' => '+39 333 123 4567',
                'vat' => 'RSSMRA80A01H501X',
                'first_name' => 'Mario',
                'last_name' => 'Rossi',
                'address' => 'Via Verdi 15, Milano',
            ],
            [
                'company_id' => $alpha?->id,
                'email' => 'luisa.bianchi@example.com',
                'phone' => '+39 320 987 6543',
                'vat' => 'BNCLSU85B02H501Y',
                'first_name' => 'Luisa',
                'last_name' => 'Bianchi',
                'address' => 'Via Manzoni 8, Milano',
            ],
            [
                'company_id' => $beta?->id,
                'email' => 'dott.verdi@example.com',
                'phone' => '+39 333 555 7777',
                'vat' => 'VRDGPP75C03H501Z',
                'first_name' => 'Giuseppe',
                'last_name' => 'Verdi',
                'address' => 'Via Dante 20, Roma',
                'notes' => 'Medical professional - priority customer',
            ],
            [
                'company_id' => $alpha?->id,
                'email' => 'paolo.neri@example.com',
                'phone' => '+39 340 111 2222',
                'first_name' => 'Paolo',
                'last_name' => 'Neri',
                'address' => 'Corso Garibaldi 12, Milano',
            ],
        ];

        foreach ($customers as $customer) {
            // Use firstOrCreate to avoid duplicates
            Customer::firstOrCreate(
                ['email' => $customer['email']],
                $customer
            );
        }

        $this->command->info('Sample customers created: ' . count($customers));
    }
}
