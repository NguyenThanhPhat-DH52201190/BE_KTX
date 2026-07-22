<?php

namespace Database\Seeders;

use App\Models\Province;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class ProvinceSeeder extends Seeder
{
    /**
     * 34 đơn vị hành chính cấp tỉnh sau sáp nhập 2025
     * (Nghị quyết 202/2025/QH15, hiệu lực 12/6/2025): 6 thành phố + 28 tỉnh.
     *
     * Mã (code) dùng mã hành chính của đơn vị trung tâm (đơn vị giữ tên).
     * Region: Bắc Bộ / Trung Bộ / Tây Nguyên / Nam Bộ (theo địa lý đơn vị giữ tên).
     */
    public function run(): void
    {
        $now = Carbon::now();

        $provinces = [
            // === 6 THÀNH PHỐ TRỰC THUỘC TRUNG ƯƠNG ===
            ['01', 'Thành phố Hà Nội', 'Bắc Bộ'],          // giữ nguyên
            ['31', 'Thành phố Hải Phòng', 'Bắc Bộ'],       // + Hải Dương
            ['46', 'Thành phố Huế', 'Trung Bộ'],           // nguyên Thừa Thiên Huế
            ['48', 'Thành phố Đà Nẵng', 'Trung Bộ'],       // + Quảng Nam
            ['79', 'Thành phố Hồ Chí Minh', 'Nam Bộ'],     // + Bình Dương + Bà Rịa - Vũng Tàu
            ['92', 'Thành phố Cần Thơ', 'Nam Bộ'],         // + Sóc Trăng + Hậu Giang

            // === 28 TỈNH ===
            // --- Bắc Bộ ---
            ['04', 'Tỉnh Cao Bằng', 'Bắc Bộ'],             // giữ nguyên
            ['08', 'Tỉnh Tuyên Quang', 'Bắc Bộ'],          // + Hà Giang
            ['10', 'Tỉnh Lào Cai', 'Bắc Bộ'],              // + Yên Bái
            ['11', 'Tỉnh Điện Biên', 'Bắc Bộ'],            // giữ nguyên
            ['12', 'Tỉnh Lai Châu', 'Bắc Bộ'],             // giữ nguyên
            ['14', 'Tỉnh Sơn La', 'Bắc Bộ'],               // giữ nguyên
            ['19', 'Tỉnh Thái Nguyên', 'Bắc Bộ'],          // + Bắc Kạn
            ['20', 'Tỉnh Lạng Sơn', 'Bắc Bộ'],             // giữ nguyên
            ['22', 'Tỉnh Quảng Ninh', 'Bắc Bộ'],           // giữ nguyên
            ['25', 'Tỉnh Phú Thọ', 'Bắc Bộ'],              // + Vĩnh Phúc + Hòa Bình
            ['27', 'Tỉnh Bắc Ninh', 'Bắc Bộ'],             // + Bắc Giang
            ['33', 'Tỉnh Hưng Yên', 'Bắc Bộ'],             // + Thái Bình
            ['37', 'Tỉnh Ninh Bình', 'Bắc Bộ'],            // + Hà Nam + Nam Định

            // --- Trung Bộ ---
            ['38', 'Tỉnh Thanh Hóa', 'Trung Bộ'],          // giữ nguyên
            ['40', 'Tỉnh Nghệ An', 'Trung Bộ'],            // giữ nguyên
            ['42', 'Tỉnh Hà Tĩnh', 'Trung Bộ'],            // giữ nguyên
            ['45', 'Tỉnh Quảng Trị', 'Trung Bộ'],          // + Quảng Bình
            ['51', 'Tỉnh Quảng Ngãi', 'Trung Bộ'],         // + Kon Tum
            ['56', 'Tỉnh Khánh Hòa', 'Trung Bộ'],          // + Ninh Thuận

            // --- Tây Nguyên ---
            ['64', 'Tỉnh Gia Lai', 'Tây Nguyên'],          // + Bình Định
            ['66', 'Tỉnh Đắk Lắk', 'Tây Nguyên'],          // + Phú Yên
            ['68', 'Tỉnh Lâm Đồng', 'Tây Nguyên'],         // + Đắk Nông + Bình Thuận

            // --- Nam Bộ ---
            ['72', 'Tỉnh Tây Ninh', 'Nam Bộ'],             // + Long An
            ['75', 'Tỉnh Đồng Nai', 'Nam Bộ'],             // + Bình Phước
            ['86', 'Tỉnh Vĩnh Long', 'Nam Bộ'],            // + Bến Tre + Trà Vinh
            ['87', 'Tỉnh Đồng Tháp', 'Nam Bộ'],            // + Tiền Giang
            ['89', 'Tỉnh An Giang', 'Nam Bộ'],             // + Kiên Giang
            ['96', 'Tỉnh Cà Mau', 'Nam Bộ'],               // + Bạc Liêu
        ];

        foreach ($provinces as [$code, $name, $region]) {
            Province::updateOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'region' => $region,
                    'is_active' => true,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }
}
