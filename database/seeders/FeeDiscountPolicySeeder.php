<?php

namespace Database\Seeders;

use App\Models\FeeDiscountPolicy;
use App\Models\PriorityCriteria;
use Illuminate\Database\Seeder;

class FeeDiscountPolicySeeder extends Seeder
{
    /**
     * Ánh xạ code diện ưu tiên → discount_percent.
     *
     * Chỉ các diện có chính sách miễn/giảm mới được liệt kê.
     * Các diện không có trong danh sách sẽ không được giảm tiền phòng.
     */
    private const DISCOUNT_MAP = [
        'UT01' => 100.00, // Con liệt sĩ / thương binh / bệnh binh
        'UT02' => 100.00, // Con người hoạt động kháng chiến bị nhiễm chất độc hóa học
        'UT04' => 30.00,  // Dân tộc thiểu số
        'UT05' => 50.00,  // Hộ nghèo / cận nghèo / xã khó khăn
    ];

    // Bổ sung thêm nếu dự án thêm mã mới sau này:
    // 'UT_BENH_NANG' => 50.00 — bệnh nặng/hiểm nghèo (nếu có code riêng)

    public function run(): void
    {
        foreach (self::DISCOUNT_MAP as $code => $discountPercent) {
            $criteria = PriorityCriteria::where('code', $code)->first();

            if (! $criteria) {
                continue;
            }

            FeeDiscountPolicy::updateOrCreate(
                ['priority_criteria_id' => $criteria->id],
                [
                    'discount_percent' => $discountPercent,
                    'is_active'        => true,
                ],
            );
        }
    }
}
