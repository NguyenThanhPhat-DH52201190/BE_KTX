<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ViolationTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $violationTypes = [
            [
                'name' => 'Gây mất trật tự',
                'level' => 'SERIOUS',
                'description' => 'Gây ồn ào, mất trật tự hoặc ảnh hưởng đến sinh viên khác trong khu nội trú.',
            ],
            [
                'name' => 'Tự ý đổi giường',
                'level' => 'MEDIUM',
                'description' => 'Tự ý đổi giường hoặc chỗ ở khi chưa có xác nhận của ban quản lý.',
            ],
            [
                'name' => 'Đưa người ngoài vào phòng',
                'level' => 'SERIOUS',
                'description' => 'Đưa người không có phận sự vào phòng ở khi chưa được phép.',
            ],
            [
                'name' => 'Phá hoại tài sản',
                'level' => 'SERIOUS',
                'description' => 'Làm hư hỏng tài sản ký túc xá hoặc tài sản dùng chung.',
            ],
            [
                'name' => 'Không tham gia vệ sinh chung',
                'level' => 'MINOR',
                'description' => 'Không thực hiện lịch vệ sinh phòng hoặc khu vực sinh hoạt chung.',
            ],
            [
                'name' => 'Hút thuốc trong phòng',
                'level' => 'MEDIUM',
                'description' => 'Hút thuốc trong phòng ở hoặc khu vực cấm hút thuốc.',
            ],
            [
                'name' => 'Nấu ăn trong phòng',
                'level' => 'MEDIUM',
                'description' => 'Sử dụng bếp, thiết bị nấu ăn hoặc thiết bị điện trái quy định trong phòng.',
            ],
            [
                'name' => 'Tàng trữ chất cấm',
                'level' => 'SERIOUS',
                'description' => 'Tàng trữ, sử dụng hoặc tiếp tay sử dụng chất cấm trong ký túc xá.',
            ],
            [
                'name' => 'Vi phạm giờ giới nghiêm',
                'level' => 'MINOR',
                'description' => 'Ra vào ký túc xá sau giờ quy định khi chưa được cho phép.',
            ],
            [
                'name' => 'Không đóng phí đúng hạn',
                'level' => 'SERIOUS',
                'description' => 'Không hoàn thành phí lưu trú hoặc các khoản phí phát sinh đúng hạn.',
            ],
        ];

        foreach ($violationTypes as $violationType) {
            DB::table('violation_types')->updateOrInsert(
                ['name' => $violationType['name']],
                $violationType,
            );
        }
    }
}
