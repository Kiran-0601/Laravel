<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::create([
            'name' => "Admin",
            'lname' => "User",
            'country' => "United States",
            'mobile' => "9974851245",
            'dob' => "23-06-2001",
            'gender' => "female",
            'email' => "admin@gmail.com",
            'email_verified_at' => now(),
            'password' => Hash::make("admin@123"),
            'user_type' => 1,
        ]);
    }
}
