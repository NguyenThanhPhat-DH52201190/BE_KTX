<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Account;
use Illuminate\Support\Facades\Hash;

class AccountSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $rows = [
            [
                'email' => 'phatnt.si.1922@gmail.com',
                'password' => Hash::make('*Bin12022004#'),
                'role' => 'student',
                'is_active' => 1,
            ],
            [
                'email' => 'kytucxadaihoccongnghesaigon@gmail.com',
                'password' => Hash::make('Stu123456789@'),
                'role' => 'admin',
                'is_active' => 1,
            ],
            [
                'email' => 'dh52201190@student.stu.edu.vn',
                'password' => Hash::make('Student12345@'),
                'role' => 'student',
                'is_active' => 1,
            ],
            [
                'email' => 'dh52201699@student.stu.edu.vn',
                'password' => Hash::make('Student12345@'),
                'role' => 'student',
                'is_active' => 1,
            ],
            [
                'email' => 'dh52200662@student.stu.edu.vn',
                'password' => Hash::make('Student12345@'),
                'role' => 'student',
                'is_active' => 1,
            ],
        ];

        // Use upsert so running seeders multiple times won't fail on unique constraint
        Account::upsert($rows, ['email'], ['password', 'role', 'is_active']);
    }
    }

