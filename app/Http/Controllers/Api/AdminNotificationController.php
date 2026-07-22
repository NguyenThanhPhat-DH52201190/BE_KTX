<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminNotification;
use Illuminate\Http\JsonResponse;

class AdminNotificationController extends Controller
{
    public function index(): JsonResponse
    {
        $items = AdminNotification::orderByDesc('created_at')
            ->limit(30)
            ->get();

        return response()->json($items);
    }

    public function unreadCount(): JsonResponse
    {
        $count = AdminNotification::where('is_read', false)->count();

        return response()->json(['count' => $count]);
    }

    public function markRead(int $id): JsonResponse
    {
        $notif = AdminNotification::findOrFail($id);

        if (! $notif->is_read) {
            $notif->update(['is_read' => true, 'read_at' => now()]);
        }

        return response()->json(['message' => 'ok']);
    }

    public function markAllRead(): JsonResponse
    {
        AdminNotification::where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now()]);

        return response()->json(['message' => 'ok']);
    }
}
