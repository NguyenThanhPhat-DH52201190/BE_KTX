<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\StudentPaymentPlan;
use App\Services\RoomFeeBillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StudentPaymentPlanController extends Controller
{
    public function __construct(private readonly RoomFeeBillingService $billingService)
    {
    }

    public function index(int $studentId): JsonResponse
    {
        Student::query()->findOrFail($studentId);

        $plans = StudentPaymentPlan::query()
            ->where('student_id', $studentId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return response()->json($plans->map(fn (StudentPaymentPlan $plan) => $this->formatPlan($plan))->values());
    }

    public function store(Request $request, int $studentId): JsonResponse
    {
        $student = Student::query()->findOrFail($studentId);

        $data = $request->validate([
            'type'               => ['required', 'string', 'in:installment,discount'],
            'discount_percent'   => ['required_if:type,discount', 'nullable', 'numeric', 'min:0', 'max:100'],
            'reason'             => ['nullable', 'string', 'max:191'],
            'support_request_id' => ['nullable', 'integer', 'exists:student_support_requests,id'],
            'created_by'         => ['nullable', 'string', 'max:191'],
        ]);

        $plan          = null;
        $errorResponse = null;

        DB::transaction(function () use ($student, $data, &$plan, &$errorResponse) {
            $hasActive = StudentPaymentPlan::query()
                ->where('student_id', $student->id)
                ->where('type', $data['type'])
                ->where('is_active', true)
                ->lockForUpdate()
                ->exists();

            if ($hasActive) {
                $errorResponse = response()->json([
                    'message' => 'Sinh viên đã có chế độ này đang active, vui lòng tắt trước khi tạo mới.',
                ], 422);
                return;
            }

            $plan = StudentPaymentPlan::query()->create([
                'student_id'          => $student->id,
                'type'                => $data['type'],
                'is_active'           => true,
                'discount_percent'    => $data['type'] === 'discount' ? $data['discount_percent'] : null,
                'reason'              => $data['reason'] ?? null,
                'support_request_id'  => $data['support_request_id'] ?? null,
                'activated_at'        => now(),
                'created_by'          => $data['created_by'] ?? null,
            ]);

            if ($data['type'] === 'installment') {
                $this->billingService->splitQuarterlyBillToMonthly($student->id);
            }
        });

        if ($errorResponse) {
            return $errorResponse;
        }

        return response()->json($this->formatPlan($plan), 201);
    }

    public function deactivate(Request $request, int $id): JsonResponse
    {
        $plan = StudentPaymentPlan::query()->findOrFail($id);

        $data = $request->validate([
            'deactivated_by' => ['nullable', 'string', 'max:191'],
        ]);

        if (! $plan->is_active) {
            return response()->json(['message' => 'Chế độ này đã được tắt trước đó.'], 422);
        }

        $plan->update([
            'is_active'       => false,
            'deactivated_at'  => now(),
            'deactivated_by'  => $data['deactivated_by'] ?? null,
        ]);

        return response()->json($this->formatPlan($plan->fresh()));
    }

    private function formatPlan(StudentPaymentPlan $plan): array
    {
        return [
            'id'                => (int) $plan->id,
            'student_id'        => (int) $plan->student_id,
            'type'              => $plan->type,
            'is_active'         => (bool) $plan->is_active,
            'discount_percent'  => $plan->discount_percent !== null ? (float) $plan->discount_percent : null,
            'reason'            => $plan->reason,
            'support_request_id' => $plan->support_request_id,
            'activated_at'      => $plan->activated_at,
            'deactivated_at'    => $plan->deactivated_at,
            'created_by'        => $plan->created_by,
            'deactivated_by'    => $plan->deactivated_by,
        ];
    }
}
