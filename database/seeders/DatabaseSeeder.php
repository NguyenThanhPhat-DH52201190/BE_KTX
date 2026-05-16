<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Gọi các seeder mẫu để mọi máy dev có dữ liệu giống nhau
        $this->call([
            AccountSeeder::class,
            StudentSeeder::class,
            RegistrationSeeder::class,
        ]);

    }
}
