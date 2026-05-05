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
        Account::insert([
            [
                'email' => 'phatnt.si.1922@gmail.com',
                'password' => Hash::make('*Bin12022004#'),
                'role' => 'student',
                'is_active' => 1
            ],
            [
                'email' => 'kytucxadaihoccongnghesaigon@gmail.com',
                'password' => Hash::make('Stu123456789@'),
                'role' => 'admin',
                'is_active' => 1
            ]
        ]);
    }
    }

