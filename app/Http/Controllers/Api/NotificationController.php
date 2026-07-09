<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\SystemAnnouncement;
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

        $limit = min((int) ($request->query('limit', 20)), 100);

        $query = DB::table('notification_recipient as nr')
            ->join('notifications as n', 'n.id', '=', 'nr.notification_id')
            ->where('nr.student_id', $student->id);

        if ($request->query('status') === 'unread') {
            $query->where('nr.is_read', false);
        } elseif ($request->query('status') === 'read') {
            $query->where('nr.is_read', true);
        }

        if ($type = $request->query('type')) {
            $query->where('n.type', $type);
        }

        $items = $query
            ->orderByDesc('n.created_at')
            ->limit($limit)
            ->select([
                'nr.id as recipient_id',
                'n.id',
                'n.title',
                'n.content',
                'n.type',
                'n.related_id',
                'n.target_type',
                'n.created_at',
                'nr.is_read',
                'nr.read_at',
            ])
            ->get();

        return response()->json($items);
    }

    public function systemAnnouncement(Request $request, int $id): JsonResponse
    {
        $student = $this->resolveStudent($request);
        if (! $student) {
            return response()->json(['message' => 'Không tìm thấy sinh viên.'], 422);
        }

        $hasNotification = DB::table('notification_recipient as nr')
            ->join('notifications as n', 'n.id', '=', 'nr.notification_id')
            ->where('nr.student_id', $student->id)
            ->where('n.related_id', $id)
            ->whereIn('n.type', ['general', 'warning', 'urgent', 'reminder', 'policy'])
            ->whereIn('n.target_type', ['active_students', 'building', 'floor', 'room', 'students'])
            ->exists();

        if (! $hasNotification) {
            return response()->json(['message' => 'Không tìm thấy thông báo.'], 404);
        }

        $announcement = SystemAnnouncement::findOrFail($id);

        return response()->json([
            'id'              => $announcement->id,
            'title'           => $announcement->title,
            'content'         => $announcement->content,
            'type'            => $announcement->type,
            'priority'        => $announcement->priority,
            'sent_at'         => optional($announcement->sent_at)->toISOString(),
            'scheduled_at'    => optional($announcement->scheduled_at)->toISOString(),
            'created_at'      => optional($announcement->created_at)->toISOString(),
            'attachment_path' => $announcement->attachment_path,
            'attachment_url'  => $this->attachmentUrl($announcement->attachment_path),
        ]);
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

    private function attachmentUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        $cleanPath = preg_replace('#^/?(api/)?storage/#', '', ltrim($path, '/'));

        return app()->environment('production')
            ? url('/api/storage/' . $cleanPath)
            : url('/storage/' . $cleanPath);
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
