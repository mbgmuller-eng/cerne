<?php

namespace Database\Seeders;

use App\Models\User;
use Database\Seeders\Concerns\DevOnlySeeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use DevOnlySeeder, WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->abortInProduction();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }
}
