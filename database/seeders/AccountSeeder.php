<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Account;
use App\Models\Student;
use Illuminate\Support\Facades\Hash;

class AccountSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Danh sách tài khoản mẫu; có thể gán student_code để liên kết với bảng students
        $seedRows = [
            ['student_code' => null, 'email' => 'kytucxadaihoccongnghesaigon@gmail.com', 'password' => 'Stu123456789@', 'role' => 'admin', 'is_active' => 1],
            ['student_code' => 'DH12234456', 'email' => 'dh12234456@student.stu.edu.vn', 'password' => 'Student12345@', 'role' => 'student', 'is_active' => 1],
            ['student_code' => 'DH52201190', 'email' => 'dh52201190@student.stu.edu.vn', 'password' => 'Student12345@', 'role' => 'student', 'is_active' => 1],
            ['student_code' => 'DH52201699', 'email' => 'dh52201699@student.stu.edu.vn', 'password' => 'Student12345@', 'role' => 'student', 'is_active' => 1],
            ['student_code' => 'DH52200662', 'email' => 'dh52200662@student.stu.edu.vn', 'password' => 'Student12345@', 'role' => 'student', 'is_active' => 1],
            ['student_code' => 'DH52201202', 'email' => 'dh52201202@student.stu.edu.vn', 'password' => 'Student12345@', 'role' => 'student', 'is_active' => 1],
        ];

        $rows = [];
        foreach ($seedRows as $r) {
            $studentId = null;
            if (!empty($r['student_code'])) {
                $s = Student::where('student_code', $r['student_code'])->first();
                if ($s) $studentId = $s->id;
            }

            $rows[] = [
                'student_id' => $studentId,
                'student_code' => $r['student_code'],
                'email' => $r['email'],
                'password' => Hash::make($r['password']),
                'role' => $r['role'],
                'is_active' => $r['is_active'],
                'otp_code' => null,
                'otp_expire' => null,
            ];
        }

        // upsert để chạy seeder nhiều lần không tạo duplicate
        Account::upsert($rows, ['email'], ['password', 'role', 'is_active', 'student_id', 'student_code', 'otp_code', 'otp_expire']);
    }
    }

