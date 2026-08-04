<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FeeDiscountPolicy;
use App\Models\PriorityCriteria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FeeDiscountPolicyController extends Controller
{
    public function index(): JsonResponse
    {
        $criteria = PriorityCriteria::with('feeDiscountPolicy')
            ->orderBy('tier')
            ->orderByDesc('priority_score')
            ->get();

        return response()->json($criteria->map(function (PriorityCriteria $item) {
            return [
                'priority_criteria_id' => $item->id,
                'code'                 => $item->code,
                'name'                 => $item->name,
                'tier'                 => $item->tier,
                'priority_score'       => $item->priority_score,
                'discount_percent'     => $item->feeDiscountPolicy?->discount_percent ?? 0,
                // Đã có sinh viên/hồ sơ thật gắn diện này — khóa sửa mã ở FE để tránh lệch với
                // hồ sơ cũ đã ghi theo mã hiện tại (báo cáo 04/08).
                'in_use'               => $item->studentPriorities()->exists() || $item->reservationPriorities()->exists(),
            ];
        }));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code'             => ['required', 'string', 'max:20', 'regex:/^UT\d+$/i', 'unique:priority_criteria,code'],
            'name'             => ['required', 'string', 'max:255'],
            'tier'             => ['required', 'integer', 'min:1', 'max:3'],
            'priority_score'   => ['required', 'integer', 'min:0', 'max:100'],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $criteria = PriorityCriteria::create([
            'code'           => $data['code'],
            'name'           => $data['name'],
            'tier'           => $data['tier'],
            'priority_score' => $data['priority_score'],
            'is_active'      => true,
        ]);

        $policy = FeeDiscountPolicy::create([
            'priority_criteria_id' => $criteria->id,
            'discount_percent'     => $data['discount_percent'] ?? 0,
        ]);

        return response()->json([
            'priority_criteria_id' => $criteria->id,
            'code'                 => $criteria->code,
            'name'                 => $criteria->name,
            'tier'                 => $criteria->tier,
            'priority_score'       => $criteria->priority_score,
            'discount_percent'     => $policy->discount_percent,
        ], 201);
    }

    public function destroy(int $priorityCriteriaId): JsonResponse
    {
        $criteria = PriorityCriteria::findOrFail($priorityCriteriaId);

        if ($criteria->studentPriorities()->exists() || $criteria->reservationPriorities()->exists()) {
            return response()->json([
                'message' => 'Không thể xóa — đã có sinh viên hoặc hồ sơ đặt chỗ thuộc diện ưu tiên này.',
            ], 422);
        }

        $criteria->delete();

        return response()->json(['message' => 'Đã xóa tiêu chí ưu tiên.']);
    }

    public function updateCriteria(Request $request, int $priorityCriteriaId): JsonResponse
    {
        $criteria = PriorityCriteria::findOrFail($priorityCriteriaId);

        $data = $request->validate([
            'code'             => [
                'required', 'string', 'max:20', 'regex:/^UT\d+$/i',
                Rule::unique('priority_criteria', 'code')->ignore($criteria->id),
            ],
            'name'             => ['required', 'string', 'max:255'],
            'tier'             => ['required', 'integer', 'min:1', 'max:3'],
            'priority_score'   => ['required', 'integer', 'min:0', 'max:100'],
            'discount_percent' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        $inUse = $criteria->studentPriorities()->exists() || $criteria->reservationPriorities()->exists();
        if ($inUse && $data['code'] !== $criteria->code) {
            return response()->json([
                'message' => 'Không thể đổi mã — đã có sinh viên hoặc hồ sơ đặt chỗ thuộc diện ưu tiên này.',
                'errors'  => ['code' => ['Không thể đổi mã vì tiêu chí đã có người dùng.']],
            ], 422);
        }

        $criteria->update([
            'code'           => $data['code'],
            'name'           => $data['name'],
            'tier'           => $data['tier'],
            'priority_score' => $data['priority_score'],
        ]);

        $policy = FeeDiscountPolicy::updateOrCreate(
            ['priority_criteria_id' => $criteria->id],
            ['discount_percent' => $data['discount_percent']],
        );

        return response()->json([
            'priority_criteria_id' => $criteria->id,
            'code'                 => $criteria->code,
            'name'                 => $criteria->name,
            'tier'                 => $criteria->tier,
            'priority_score'       => $criteria->priority_score,
            'discount_percent'     => $policy->discount_percent,
        ]);
    }

}
