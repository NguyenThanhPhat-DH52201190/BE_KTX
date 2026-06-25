<?php

namespace App\Services;

use App\Models\FeeDiscountPolicy;
use App\Models\StudentPriority;
use App\Models\Occupancy;

class FeeDiscountService
{
    /**
     * Tính miễn/giảm tiền phòng cho một occupancy.
     *
     * Lấy tất cả student_priority có status=verified của sinh viên trong đăng ký đó,
     * đối chiếu với fee_discount_policies đang active, chọn mức giảm cao nhất.
     *
     * @return array{
     *   original_amount: int,
     *   discount_percent: float,
     *   discount_amount: int,
     *   final_amount: int,
     *   discount_reason: string|null
     * }
     */
    public function calculate(Occupancy $occupancy, int $originalAmount): array
    {
        $noDiscount = [
            'original_amount' => $originalAmount,
            'discount_percent' => 0.0,
            'discount_amount'  => 0,
            'final_amount'     => $originalAmount,
            'discount_reason'  => null,
        ];

        $registrationId = $occupancy->registration_id;
        $studentId      = $occupancy->student_id;

        if (! $registrationId || ! $studentId) {
            return $noDiscount;
        }

        // Lấy các diện ưu tiên đã verified của sinh viên trong đăng ký này
        $verifiedPriorityIds = StudentPriority::query()
            ->where('registration_id', $registrationId)
            ->where('student_id', $studentId)
            ->where('status', 'verified')
            ->pluck('priority_criteria_id');

        if ($verifiedPriorityIds->isEmpty()) {
            return $noDiscount;
        }

        // Tìm policy active có mức giảm cao nhất
        $bestPolicy = FeeDiscountPolicy::query()
            ->with('criteria')
            ->whereIn('priority_criteria_id', $verifiedPriorityIds)
            ->where('is_active', true)
            ->orderByDesc('discount_percent')
            ->first();

        if (! $bestPolicy || $bestPolicy->discount_percent <= 0) {
            return $noDiscount;
        }

        $discountPercent = (float) $bestPolicy->discount_percent;
        $discountAmount  = (int) round($originalAmount * $discountPercent / 100);
        $finalAmount     = max(0, $originalAmount - $discountAmount);

        return [
            'original_amount' => $originalAmount,
            'discount_percent' => $discountPercent,
            'discount_amount'  => $discountAmount,
            'final_amount'     => $finalAmount,
            'discount_reason'  => $bestPolicy->criteria?->name ?? null,
        ];
    }
}
