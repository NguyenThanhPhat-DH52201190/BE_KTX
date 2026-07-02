<?php

namespace App\Services;

use App\Models\FeeDiscountPolicy;
use App\Models\StudentPaymentPlan;
use App\Models\StudentPriority;
use App\Models\Occupancy;

class FeeDiscountService
{
    /**
     * Tính miễn/giảm tiền phòng cho một occupancy.
     *
     * So sánh 2 nguồn giảm giá và lấy mức % cao hơn (không cộng dồn):
     * - Theo chính sách: student_priority verified đối chiếu fee_discount_policies active.
     * - Theo chế độ đặc biệt admin duyệt thủ công: student_payment_plans (type=discount, active).
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

        $policyPercent = 0.0;
        $policyReason  = null;

        // Lấy các diện ưu tiên đã verified của sinh viên trong đăng ký này
        $verifiedPriorityIds = StudentPriority::query()
            ->where('registration_id', $registrationId)
            ->where('student_id', $studentId)
            ->where('status', 'verified')
            ->pluck('priority_criteria_id');

        if ($verifiedPriorityIds->isNotEmpty()) {
            // Tìm policy active có mức giảm cao nhất
            $bestPolicy = FeeDiscountPolicy::query()
                ->with('criteria')
                ->whereIn('priority_criteria_id', $verifiedPriorityIds)
                ->where('is_active', true)
                ->orderByDesc('discount_percent')
                ->first();

            if ($bestPolicy && $bestPolicy->discount_percent > 0) {
                $policyPercent = (float) $bestPolicy->discount_percent;
                $policyReason  = $bestPolicy->criteria?->name ?? null;
            }
        }

        // Chế độ giảm giá dài hạn do admin duyệt thủ công cho sinh viên khó khăn
        $plan = StudentPaymentPlan::query()
            ->where('student_id', $studentId)
            ->where('type', 'discount')
            ->where('is_active', true)
            ->first();

        $planPercent = $plan ? (float) $plan->discount_percent : 0.0;

        if ($planPercent > $policyPercent) {
            $discountPercent = $planPercent;
            $discountReason  = $plan->reason ?: 'Hỗ trợ sinh viên khó khăn';
        } else {
            $discountPercent = $policyPercent;
            $discountReason  = $policyReason;
        }

        if ($discountPercent <= 0) {
            return $noDiscount;
        }

        $discountAmount = (int) round($originalAmount * $discountPercent / 100);
        $finalAmount    = max(0, $originalAmount - $discountAmount);

        return [
            'original_amount' => $originalAmount,
            'discount_percent' => $discountPercent,
            'discount_amount'  => $discountAmount,
            'final_amount'     => $finalAmount,
            'discount_reason'  => $discountReason,
        ];
    }
}
