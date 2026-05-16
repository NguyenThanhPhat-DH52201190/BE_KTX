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
        // Chạy StudentSeeder trước để AccountSeeder có thể ánh xạ student_id
        $this->call([
            StudentSeeder::class,
            AccountSeeder::class,
            RegistrationSeeder::class,
        ]);

    }
}
