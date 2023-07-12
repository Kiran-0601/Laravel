<?php

namespace Database\Seeders;

use App\Models\FeedbackType;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class FeedbackTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $types = [
            [
                'feeback_type' => 'New Feature',
            ],
            [
                'feeback_type' => 'Bug',
            ],
            [
                'feeback_type' => 'Design',
            ],
            [
                'feeback_type' => 'General',
            ],
        ];
        FeedbackType::insert($types);
    }
}
