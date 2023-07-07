<?php

namespace Database\Seeders;

use App\Models\Country;
use Illuminate\Database\Seeder;

class CountrySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $countries = [
            [
                'code' => 'US',
                'name' => 'United States',
            ],
            [
                'code' => 'CA',
                'name' => 'Canada',
            ],
            [
                'code' => 'UK',
                'name' => 'United Kingdom',
            ],
        ];
        Country::insert($countries);
    }
}
