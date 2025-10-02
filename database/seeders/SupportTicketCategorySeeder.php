<?php

namespace Database\Seeders;

use App\Models\SupportTicketCategory;
use Illuminate\Database\Seeder;

class SupportTicketCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            'General Support',
            'Billing & Payments',
            'Technical Issues',
            'Account Management',
        ];

        foreach ($categories as $name) {
            SupportTicketCategory::firstOrCreate(['name' => $name]);
        }
    }
}
