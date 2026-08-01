<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FeeDiscountPolicy;
use App\Models\PriorityCriteria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
                'is_active'            => $item->feeDiscountPolicy?->is_active ?? false,
            ];
        }));
    }

    public function update(Request $request, int $priorityCriteriaId): JsonResponse
    {
        $criteria = PriorityCriteria::findOrFail($priorityCriteriaId);

        $data = $request->validate([
            'discount_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'is_active'        => ['required', 'boolean'],
        ]);

        $policy = FeeDiscountPolicy::updateOrCreate(
            ['priority_criteria_id' => $criteria->id],
            $data,
        );

        return response()->json([
            'priority_criteria_id' => $criteria->id,
            'code'                 => $criteria->code,
            'name'                 => $criteria->name,
            'tier'                 => $criteria->tier,
            'priority_score'       => $criteria->priority_score,
            'discount_percent'     => $policy->discount_percent,
            'is_active'            => $policy->is_active,
        ]);
    }
}
