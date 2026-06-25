<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\AdminNotification;
use App\Models\Bed;
use App\Models\MaintenanceRequest;
use App\Models\Notification;
use App\Models\Occupancy;
use App\Models\Room;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AutoStartMaintenanceCommand extends Command
{
    protected $signature   = 'maintenance:auto-start {--dry-run : Chạy thử, không ghi database hay gửi email}';
    protected $description = 'Tự động bắt đầu bảo trì phòng đã đến ngày started_at';

    private bool $isDry;
    private int  $started = 0;

    public function handle(): int
    {
        $this->isDry = (bool) $this->option('dry-run');

        $this->info(sprintf(
            '[maintenance:auto-start] %s (%s)',
            $this->isDry ? 'DRY RUN — không ghi dữ liệu.' : 'Bắt đầu...',
            Carbon::now()->toDateTimeString(),
        ));

        $requests = MaintenanceRequest::query()
            ->where('type', 'ROOM')
            ->where('status', 'PENDING')
            ->whereNotNull('started_at')
            ->whereDate('started_at', '<=', Carbon::today())
            ->get();

        $this->info("Tìm thấy {$requests->count()} bảo trì phòng cần bắt đầu.");

        foreach ($requests as $mr) {
            $this->startRoom($mr);
        }

        $this->info("Đã bắt đầu: {$this->started} bảo trì.");

        return self::SUCCESS;
    }

    private function startRoom(MaintenanceRequest $mr): void
    {
        if ($this->isDry) {
            $this->line("  [DRY] Would start ROOM maintenance #{$mr->id} (room_id={$mr->room_id})");
            $this->started++;
            return;
        }

        $assignments = $mr->pending_assignments;

        if (empty($assignments)) {
            $this->warn("  [SKIP] ROOM maintenance #{$mr->id}: không có pending_assignments.");
            MaintenanceRequest::query()->where('id', $mr->id)->update([
                'status'     => 'IN_PROGRESS',
                'pending_assignments' => null,
            ]);
            return;
        }

        $pendingEmails = [];

        try {
            DB::transaction(function () use ($mr, $assignments, &$pendingEmails) {
                $room = Room::query()->with(['floor', 'beds'])->lockForUpdate()->findOrFail($mr->room_id);

                $targetIds  = collect($assignments)->pluck('target_bed_id')->map(fn ($id) => (int) $id);
                $targetBeds = Bed::query()->with('room.floor')
                    ->whereIn('id', $targetIds->all())
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                foreach ($assignments as $assignment) {
                    $occupancy = Occupancy::occupiedBedsQuery()
                        ->where('id', (int) $assignment['occupancy_id'])
                        ->where('room_id', $room->id)
                        ->with(['student', 'bed'])
                        ->lockForUpdate()
                        ->first();

                    if (! $occupancy) {
                        $this->warn("  [SKIP occupancy #{$assignment['occupancy_id']}] Không tìm thấy hoặc không còn ACTIVE trong phòng.");
                        continue;
                    }

                    $targetBed = $targetBeds->get((int) $assignment['target_bed_id']);

                    if (! $targetBed) {
                        $this->warn("  [SKIP occupancy #{$occupancy->id}] Không tìm thấy giường đích #{$assignment['target_bed_id']}.");
                        continue;
                    }

                    // Kiểm tra giường đích còn trống không
                    $occupied = Occupancy::occupiedBedsQuery()
                        ->where('bed_id', $targetBed->id)
                        ->exists();

                    if ($occupied || strtolower((string) $targetBed->status) === 'maintenance') {
                        $this->warn("  [SKIP occupancy #{$occupancy->id}] Giường đích #{$targetBed->id} đã bị chiếm hoặc bảo trì.");
                        continue;
                    }

                    $oldRoomId = (int) $occupancy->room_id;
                    $oldBedId  = (int) $occupancy->bed_id;
                    $oldBedNum = $occupancy->bed?->bed_number ?? $oldBedId;

                    DB::table('occupancy')
                        ->where('bed_id', $targetBed->id)
                        ->where('id', '!=', $occupancy->id)
                        ->whereNotIn('status', Occupancy::OCCUPIED_BED_STATUSES)
                        ->update(['bed_id' => null]);

                    $occupancy->room_id = $targetBed->room_id;
                    $occupancy->bed_id  = $targetBed->id;
                    $occupancy->save();

                    $targetBed->status = 'active';
                    $targetBed->save();

                    if ($occupancy->bed) {
                        $occupancy->bed->status = 'maintenance';
                        $occupancy->bed->save();
                    }

                    DB::table('room_change_log')->insert([
                        'occupancy_id'           => $occupancy->id,
                        'old_room_id'            => $oldRoomId,
                        'old_bed_id'             => $oldBedId,
                        'new_room_id'            => (int) $targetBed->room_id,
                        'new_bed_id'             => (int) $targetBed->id,
                        'transfer_reason'        => 'ROOM_MAINTENANCE',
                        'change_type'            => 'TEMPORARY_MAINTENANCE',
                        'maintenance_request_id' => $mr->id,
                        'status'                 => 'ACTIVE',
                        'change_source'          => 'system',
                        'is_temporary'           => true,
                        'expected_return_date'   => $mr->getRawOriginal('expected_end_at'),
                        'transferred_at'         => now(),
                    ]);

                    $student = $occupancy->student;
                    if ($student) {
                        $oldRoomCode = ($room->floor?->building_code ?? '') . $room->room_number;
                        $newRoomCode = ($targetBed->room?->floor?->building_code ?? '') . ($targetBed->room?->room_number ?? '');
                        $expectedEnd = Carbon::parse($mr->expected_end_at)->format('d/m/Y');
                        $title   = 'Bảo trì phòng bắt đầu — Đã chuyển phòng tạm thời';
                        $content = "Hôm nay bắt đầu bảo trì Phòng {$oldRoomCode} (dự kiến đến {$expectedEnd}). "
                                 . "Bạn đã được chuyển tạm từ Giường {$oldBedNum} (Phòng {$oldRoomCode}) "
                                 . "sang Giường {$targetBed->bed_number} (Phòng {$newRoomCode}).";
                        $this->saveNotification($student->id, $title, $content, 'room_maintenance_start');
                        $pendingEmails[] = $this->buildStudentPayload($student, $title, $content);
                    }
                }

                MaintenanceRequest::query()->where('id', $mr->id)->update([
                    'status'              => 'IN_PROGRESS',
                    'pending_assignments' => null,
                ]);

                $room->status = 'maintenance';
                $room->save();
                $room->beds()->update(['status' => 'maintenance']);

                // Thông báo admin
                $roomCode    = ($room->floor?->building_code ?? '') . $room->room_number;
                $expectedEnd = Carbon::parse($mr->expected_end_at)->format('d/m/Y');
                $adminTitle  = "Bảo trì phòng {$roomCode} đã bắt đầu";
                $adminContent = "Hệ thống đã tự động bắt đầu bảo trì Phòng {$roomCode} hôm nay. "
                              . "Dự kiến hoàn thành: {$expectedEnd}. Sinh viên đã được di dời sang phòng tạm.";
                $this->saveAdminNotification($adminTitle, $adminContent, 'room_maintenance_start', $mr->id);
                $pendingEmails[] = $this->buildAdminPayload($adminTitle, $adminContent);
            });

            foreach ($pendingEmails as $payload) {
                $this->sendEmail($payload);
            }

            $this->line("  [DONE] ROOM maintenance #{$mr->id} — room_id={$mr->room_id}");
            $this->started++;
        } catch (\Throwable $e) {
            Log::error("[maintenance:auto-start] ROOM #{$mr->id} thất bại: " . $e->getMessage());
            $this->error("  [FAIL] ROOM maintenance #{$mr->id}: " . $e->getMessage());
        }
    }

    private function saveNotification(int $studentId, string $title, string $content, string $type): void
    {
        try {
            $notification = Notification::create([
                'student_id'  => $studentId,
                'title'       => $title,
                'content'     => $content,
                'type'        => $type,
                'target_type' => 'individual',
                'send_email'  => true,
            ]);
            DB::table('notification_recipient')->insert([
                'notification_id' => $notification->id,
                'student_id'      => $studentId,
                'is_read'         => false,
                'read_at'         => null,
            ]);
        } catch (\Exception) {}
    }

    private function saveAdminNotification(string $title, string $content, string $type, int $relatedId): void
    {
        try {
            AdminNotification::create([
                'title'      => $title,
                'content'    => $content,
                'type'       => $type,
                'related_id' => $relatedId,
                'created_at' => now(),
            ]);
        } catch (\Exception) {}
    }

    private function buildStudentPayload(object $student, string $title, string $content): array
    {
        $name   = htmlspecialchars($student->full_name ?? '');
        $eTitle = htmlspecialchars($title);
        $eBody  = nl2br(htmlspecialchars($content));

        return [
            'email'   => $student->email ?? '',
            'name'    => $student->full_name ?? '',
            'subject' => 'KTX — ' . $title,
            'body'    => "<div style='font-family:sans-serif;max-width:600px;margin:0 auto;padding:24px;color:#1f3152'>
                <h2 style='color:#244cb8;margin-top:0'>{$eTitle}</h2>
                <p>Xin chào <strong>{$name}</strong>,</p>
                <p>{$eBody}</p>
                <p style='color:#6b7280;font-size:12px;margin-top:32px'>Email này được gửi tự động bởi hệ thống quản lý KTX.</p>
            </div>",
        ];
    }

    private function buildAdminPayload(string $title, string $content): array
    {
        $adminEmails = Account::where('role', 'admin')
            ->with('student')
            ->get()
            ->map(fn ($acc) => $acc->student?->email)
            ->push(config('auth.admin_login_email'))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return [
            'admin_emails' => $adminEmails,
            'subject'      => 'KTX — ' . $title,
            'body'         => "<div style='font-family:sans-serif;max-width:600px;margin:0 auto;padding:24px;color:#1f3152'>
                <h2 style='color:#244cb8;margin-top:0'>" . htmlspecialchars($title) . "</h2>
                <p>" . nl2br(htmlspecialchars($content)) . "</p>
                <p style='color:#6b7280;font-size:12px;margin-top:32px'>Email này được gửi tự động bởi hệ thống quản lý KTX.</p>
            </div>",
        ];
    }

    private function sendEmail(array $payload): void
    {
        // Admin bulk payload
        if (isset($payload['admin_emails'])) {
            foreach ($payload['admin_emails'] as $email) {
                try {
                    Mail::send([], [], function ($message) use ($email, $payload) {
                        $message->to($email)->subject($payload['subject'])->html($payload['body']);
                    });
                } catch (\Exception) {}
            }
            return;
        }

        if (empty($payload['email'])) {
            return;
        }
        try {
            Mail::send([], [], function ($message) use ($payload) {
                $message->to($payload['email'], $payload['name'])
                    ->subject($payload['subject'])
                    ->html($payload['body']);
            });
        } catch (\Exception) {}
    }
}
