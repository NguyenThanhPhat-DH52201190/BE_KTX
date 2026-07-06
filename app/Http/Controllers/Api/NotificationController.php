<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    // Lấy sinh viên từ tài khoản đang đăng nhập (route đã bảo vệ auth:sanctum + role:student).
    private function resolveStudent(Request $request): ?Student
    {
        $account = $request->user();

        return $account?->student_id ? Student::find($account->student_id) : null;
    }

    public function index(Request $request): JsonResponse
    {
        $student = $this->resolveStudent($request);
        if (! $student) {
            return response()->json(['message' => 'Không tìm thấy sinh viên.'], 422);
        }

        $limit = min((int) ($request->query('limit', 20)), 50);

        $items = DB::table('notification_recipient as nr')
            ->join('notifications as n', 'n.id', '=', 'nr.notification_id')
            ->where('nr.student_id', $student->id)
            ->orderByDesc('n.created_at')
            ->limit($limit)
            ->select([
                'nr.id as recipient_id',
                'n.id',
                'n.title',
                'n.content',
                'n.type',
                'n.related_id',
                'n.created_at',
                'nr.is_read',
                'nr.read_at',
            ])
            ->get();

        return response()->json($items);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $student = $this->resolveStudent($request);
        if (! $student) {
            return response()->json(['count' => 0]);
        }

        $count = DB::table('notification_recipient as nr')
            ->join('notifications as n', 'n.id', '=', 'nr.notification_id')
            ->where('nr.student_id', $student->id)
            ->where('nr.is_read', false)
            ->count();

        return response()->json(['count' => $count]);
    }

    public function markRead(Request $request, int $id): JsonResponse
    {
        $student = $this->resolveStudent($request);
        if (! $student) {
            return response()->json(['message' => 'Không tìm thấy sinh viên.'], 422);
        }

        DB::table('notification_recipient')
            ->where('id', $id)
            ->where('student_id', $student->id)
            ->update(['is_read' => true, 'read_at' => now()]);

        return response()->json(['success' => true]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $student = $this->resolveStudent($request);
        if (! $student) {
            return response()->json(['message' => 'Không tìm thấy sinh viên.'], 422);
        }

        DB::table('notification_recipient')
            ->where('student_id', $student->id)
            ->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now()]);

        return response()->json(['success' => true]);
    }
}
