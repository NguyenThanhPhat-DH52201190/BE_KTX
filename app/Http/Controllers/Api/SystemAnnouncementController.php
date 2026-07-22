<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSystemAnnouncementRequest;
use App\Models\Occupancy;
use App\Models\SystemAnnouncement;
use App\Models\SystemAnnouncementRecipient;
use App\Services\SystemAnnouncementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SystemAnnouncementController extends Controller
{
    public function __construct(private readonly SystemAnnouncementService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = SystemAnnouncement::query()
            ->withCount('recipients')
            ->withCount(['recipients as email_sent_count' => fn ($q) => $q->where('email_status', 'sent')])
            ->withCount(['recipients as email_failed_count' => fn ($q) => $q->where('email_status', 'failed')])
            ->withCount(['recipients as email_pending_count' => fn ($q) => $q->where('email_status', 'pending')]);

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('content', 'like', "%{$search}%");
            });
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }

        $items = $query->orderByDesc('created_at')
            ->paginate(min((int) $request->query('per_page', 10), 50));

        return response()->json([
            'data' => collect($items->items())->map(fn (SystemAnnouncement $announcement) => $this->formatListItem($announcement))->values(),
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page'    => $items->lastPage(),
                'per_page'     => $items->perPage(),
                'total'        => $items->total(),
            ],
        ]);
    }

    public function stats(): JsonResponse
    {
        return response()->json([
            'total'     => SystemAnnouncement::count(),
            'draft'     => SystemAnnouncement::where('status', 'draft')->count(),
            'scheduled' => SystemAnnouncement::where('status', 'scheduled')->count(),
            'sent'      => SystemAnnouncement::where('status', 'sent')->count(),
            'failed'    => SystemAnnouncement::where('status', 'failed')->count(),
        ]);
    }

    public function store(StoreSystemAnnouncementRequest $request): JsonResponse
    {
        $data = $this->payload($request);
        $data['updated_by'] = $request->user()->id;
        $data['status'] = $this->initialStatus($request);

        $announcement = SystemAnnouncement::create($data);

        if ($request->input('action') === 'send') {
            try {
                $announcement = $this->service->send($announcement);
            } catch (\RuntimeException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        }

        return response()->json($this->formatDetail($announcement->fresh()), 201);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json($this->formatDetail(SystemAnnouncement::findOrFail($id), true));
    }

    public function update(StoreSystemAnnouncementRequest $request, int $id): JsonResponse
    {
        $announcement = SystemAnnouncement::findOrFail($id);
        if (! in_array($announcement->status, ['draft', 'scheduled'], true)) {
            return response()->json(['message' => 'Chỉ có thể chỉnh sửa thông báo nháp hoặc hẹn giờ.'], 422);
        }

        $data = $this->payload($request);
        $data['updated_by'] = $request->user()->id;
        $data['status'] = $this->initialStatus($request);
        $announcement->update($data);

        if ($request->input('action') === 'send') {
            try {
                $announcement = $this->service->send($announcement);
            } catch (\RuntimeException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        }

        return response()->json($this->formatDetail($announcement->fresh()));
    }

    public function destroy(int $id): JsonResponse
    {
        $announcement = SystemAnnouncement::findOrFail($id);
        if ($announcement->status !== 'draft') {
            return response()->json(['message' => 'Chỉ có thể xóa thông báo nháp.'], 422);
        }

        $announcement->delete();

        return response()->json(['message' => 'Đã xóa thông báo.']);
    }

    public function send(int $id): JsonResponse
    {
        $announcement = SystemAnnouncement::findOrFail($id);
        if (! in_array($announcement->status, ['draft', 'scheduled', 'failed'], true)) {
            return response()->json(['message' => 'Thông báo này không thể gửi lại từ trạng thái hiện tại.'], 422);
        }

        try {
            return response()->json($this->formatDetail($this->service->send($announcement), true));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function unschedule(Request $request, int $id): JsonResponse
    {
        $announcement = SystemAnnouncement::findOrFail($id);
        if ($announcement->status !== 'scheduled') {
            return response()->json(['message' => 'Chỉ có thể hủy hẹn giờ đối với thông báo đang ở trạng thái hẹn giờ.'], 422);
        }

        // Cùng giá trị đích mà initialStatus() trả về khi action=draft — chuyển thẳng về nháp,
        // không bắt đi qua validate toàn bộ payload như update() để thao tác gọn 1 bước.
        $announcement->update([
            'status'      => 'draft',
            'updated_by'  => $request->user()->id,
        ]);

        return response()->json($this->formatDetail($announcement->fresh()));
    }

    public function resendEmail(int $id): JsonResponse
    {
        $announcement = SystemAnnouncement::findOrFail($id);
        $queued = $this->service->resendFailedEmails($announcement);

        return response()->json(['queued' => $queued]);
    }

    public function targetOptions(): JsonResponse
    {
        $buildings = DB::table('buildings')
            ->orderBy('building_code')
            ->get(['building_code', 'name']);
        $floors = DB::table('floors')
            ->orderBy('building_code')
            ->orderBy('floor_number')
            ->get(['id', 'building_code', 'floor_number']);
        $rooms = DB::table('rooms')
            ->join('floors', 'floors.id', '=', 'rooms.floor_id')
            ->orderBy('floors.building_code')
            ->orderBy('rooms.room_number')
            ->get([
                'rooms.id',
                'rooms.room_number',
                'floors.building_code',
                'floors.floor_number',
            ]);

        return response()->json([
            'buildings' => $buildings,
            'floors'    => $floors,
            'rooms'     => $rooms,
        ]);
    }

    private function payload(StoreSystemAnnouncementRequest $request): array
    {
        $data = $request->validated();
        unset($data['action']);

        $data['target_value'] = $this->service->normalizeTargetValue($data['target_type'], $data['target_value'] ?? null);

        return $data;
    }

    private function initialStatus(StoreSystemAnnouncementRequest $request): string
    {
        if ($request->input('action') === 'draft') {
            return 'draft';
        }

        if ($request->input('action') === 'schedule') {
            return 'scheduled';
        }

        if ($request->filled('scheduled_at') && $request->date('scheduled_at')->isFuture()) {
            return 'scheduled';
        }

        return 'draft';
    }

    private function formatListItem(SystemAnnouncement $announcement): array
    {
        $readCount = $this->readCount($announcement);

        return [
            'id'                  => $announcement->id,
            'title'               => $announcement->title,
            'content'             => $announcement->content,
            'type'                => $announcement->type,
            'priority'            => $announcement->priority,
            'target_type'         => $announcement->target_type,
            'target_value'        => $this->decodeTargetValue($announcement->target_value),
            'target_label'        => $this->service->targetLabel($announcement),
            'send_web'            => $announcement->send_web,
            'send_email'          => $announcement->send_email,
            'scheduled_at'        => optional($announcement->scheduled_at)->toISOString(),
            'sent_at'             => optional($announcement->sent_at)->toISOString(),
            'status'              => $announcement->status,
            'recipient_count'     => $announcement->recipients_count ?? 0,
            'read_count'          => $readCount,
            'unread_count'        => max(0, ($announcement->recipients_count ?? 0) - $readCount),
            'email_sent_count'    => $announcement->email_sent_count ?? 0,
            'email_failed_count'  => $announcement->email_failed_count ?? 0,
            'email_pending_count' => $announcement->email_pending_count ?? 0,
            'created_at'          => optional($announcement->created_at)->toISOString(),
            'updated_at'          => optional($announcement->updated_at)->toISOString(),
        ];
    }

    private function formatDetail(SystemAnnouncement $announcement, bool $withRecipients = false): array
    {
        $announcement->loadCount('recipients');
        $announcement->loadCount(['recipients as email_sent_count' => fn ($q) => $q->where('email_status', 'sent')]);
        $announcement->loadCount(['recipients as email_failed_count' => fn ($q) => $q->where('email_status', 'failed')]);
        $announcement->loadCount(['recipients as email_pending_count' => fn ($q) => $q->where('email_status', 'pending')]);

        $data = $this->formatListItem($announcement);
        $data['stats'] = [
            'total_recipients' => $announcement->recipients_count ?? 0,
            'read'             => $data['read_count'],
            'unread'           => $data['unread_count'],
            'email_sent'       => $announcement->email_sent_count ?? 0,
            'email_failed'     => $announcement->email_failed_count ?? 0,
            'email_pending'    => $announcement->email_pending_count ?? 0,
        ];

        if ($withRecipients) {
            $data['recipients'] = $this->recipients($announcement);
        }

        return $data;
    }

    private function recipients(SystemAnnouncement $announcement): array
    {
        $recipients = SystemAnnouncementRecipient::with('student')
            ->where('system_announcement_id', $announcement->id)
            ->orderBy('id')
            ->get();
        $studentIds = $recipients->pluck('student_id')->all();
        $occupancies = Occupancy::query()
            ->with(['room.floor'])
            ->where('status', 'ACTIVE')
            ->whereIn('student_id', $studentIds)
            ->get()
            ->keyBy('student_id');
        $reads = $recipients->pluck('notification_id')->filter()->unique()->isEmpty()
            ? collect()
            : DB::table('notification_recipient')
                ->whereIn('notification_id', $recipients->pluck('notification_id')->filter()->unique()->all())
                ->whereIn('student_id', $studentIds)
                ->get()
                ->keyBy(fn ($row) => $row->notification_id . ':' . $row->student_id);

        return $recipients->map(function (SystemAnnouncementRecipient $recipient) use ($occupancies, $reads) {
            $student = $recipient->student;
            $occupancy = $occupancies->get($recipient->student_id);
            $room = $occupancy?->room;
            $read = $reads->get($recipient->notification_id . ':' . $recipient->student_id);

            return [
                'id'             => $recipient->id,
                'student_id'     => $recipient->student_id,
                'student_code'   => $student?->student_code,
                'full_name'      => $student?->full_name,
                'email'          => $student?->email,
                'room'           => $room ? (($room->floor?->building_code ?? '') . $room->room_number) : null,
                'read_at'        => $read?->read_at,
                'is_read'        => (bool) ($read?->is_read ?? false),
                'email_status'   => $recipient->email_status,
                'email_sent_at'  => optional($recipient->email_sent_at)->toISOString(),
                'email_error'    => $recipient->email_error,
            ];
        })->values()->all();
    }

    private function readCount(SystemAnnouncement $announcement): int
    {
        $notificationIds = SystemAnnouncementRecipient::where('system_announcement_id', $announcement->id)
            ->whereNotNull('notification_id')
            ->pluck('notification_id')
            ->unique()
            ->all();

        if (empty($notificationIds)) {
            return 0;
        }

        return DB::table('notification_recipient')
            ->whereIn('notification_id', $notificationIds)
            ->where('is_read', true)
            ->count();
    }

    private function decodeTargetValue(?string $targetValue): mixed
    {
        if ($targetValue === null || $targetValue === '') {
            return null;
        }

        $decoded = json_decode($targetValue, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $targetValue;
    }
}
