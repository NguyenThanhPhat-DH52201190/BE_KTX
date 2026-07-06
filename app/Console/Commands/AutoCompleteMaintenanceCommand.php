<?php

namespace App\Console\Commands;

use App\Mail\GenericNotificationMail;
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

class AutoCompleteMaintenanceCommand extends Command
{
    protected $signature   = 'maintenance:auto-complete {--dry-run : Chạy thử, không ghi database hay gửi email}';
    protected $description = 'Tự động hoàn tất bảo trì đã đến ngày dự kiến kết thúc';

    private bool $isDry;
    private int  $completed = 0;

    public function handle(): int
    {
        $this->isDry = (bool) $this->option('dry-run');
        $now         = Carbon::now();

        $this->info(sprintf(
            '[maintenance:auto-complete] %s (%s)',
            $this->isDry ? 'DRY RUN — không ghi dữ liệu.' : 'Bắt đầu...',
            $now->toDateTimeString(),
        ));

        $requests = MaintenanceRequest::query()
            ->where('status', 'IN_PROGRESS')
            ->whereNotNull('expected_end_at')
            ->whereDate('expected_end_at', '<=', Carbon::today())
            ->get();

        $this->info("Tìm thấy {$requests->count()} bảo trì cần hoàn tất.");

        foreach ($requests as $request) {
            if ($request->type === 'ROOM') {
                $this->completeRoom($request);
            } elseif ($request->type === 'BED') {
                $this->completeBed($request);
            }
        }

        $this->info("Hoàn tất: {$this->completed} bảo trì.");

        return self::SUCCESS;
    }

    private function completeRoom(MaintenanceRequest $mr): void
    {
        if ($this->isDry) {
            $this->line("  [DRY] Would complete ROOM maintenance #{$mr->id} (room_id={$mr->room_id})");
            $this->completed++;
            return;
        }

        $pendingEmails = [];

        try {
            DB::transaction(function () use ($mr, &$pendingEmails) {
                $room = Room::query()->with(['floor', 'beds'])->lockForUpdate()->findOrFail($mr->room_id);

                $logs = DB::table('room_change_log')
                    ->where('old_room_id', $room->id)
                    ->where('change_type', 'TEMPORARY_MAINTENANCE')
                    ->where('transfer_reason', 'ROOM_MAINTENANCE')
                    ->where('status', 'ACTIVE')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                foreach ($logs as $log) {
                    $this->returnRoomLog($log, $pendingEmails);
                }

                MaintenanceRequest::query()->where('id', $mr->id)
                    ->update(['status' => 'COMPLETED', 'completed_at' => now()]);

                $room->status = 'active';
                $room->save();
                foreach ($room->beds as $bed) {
                    $bed->status = 'active';
                    $bed->save();
                }
            });

            foreach ($pendingEmails as $payload) {
                $this->sendEmail($payload);
            }

            $this->line("  [DONE] ROOM maintenance #{$mr->id} — room_id={$mr->room_id}");
            $this->completed++;
        } catch (\Throwable $e) {
            Log::error("[maintenance:auto-complete] ROOM #{$mr->id} thất bại: " . $e->getMessage());
            $this->error("  [FAIL] ROOM maintenance #{$mr->id}: " . $e->getMessage());
        }
    }

    private function completeBed(MaintenanceRequest $mr): void
    {
        if ($this->isDry) {
            $this->line("  [DRY] Would complete BED maintenance #{$mr->id} (bed_id={$mr->bed_id})");
            $this->completed++;
            return;
        }

        $pendingEmails = [];

        try {
            DB::transaction(function () use ($mr, &$pendingEmails) {
                $room      = Room::query()->with(['floor'])->lockForUpdate()->findOrFail($mr->room_id);
                $sourceBed = Bed::query()->where('room_id', $room->id)->where('id', $mr->bed_id)->lockForUpdate()->first();

                if (! $sourceBed) {
                    $this->warn("  [SKIP] BED maintenance #{$mr->id}: không tìm thấy giường nguồn.");
                    return;
                }

                $log = DB::table('room_change_log')
                    ->where('old_bed_id', $sourceBed->id)
                    ->where('change_type', 'TEMPORARY_MAINTENANCE')
                    ->where('status', 'ACTIVE')
                    ->where(function ($q) {
                        $q->where('transfer_reason', 'BED_MAINTENANCE')
                            ->orWhereExists(function ($sub) {
                                $sub->select(DB::raw(1))
                                    ->from('maintenance_requests')
                                    ->whereColumn('maintenance_requests.id', 'room_change_log.maintenance_request_id')
                                    ->where('maintenance_requests.type', 'BED');
                            });
                    })
                    ->orderByDesc('id')
                    ->lockForUpdate()
                    ->first();

                if (! $log) {
                    $this->warn("  [SKIP] BED maintenance #{$mr->id}: không tìm thấy lịch sử chuyển tạm.");
                    return;
                }

                $occupancy = Occupancy::occupiedBedsQuery()
                    ->where('id', $log->occupancy_id)
                    ->with('student')
                    ->lockForUpdate()
                    ->first();

                if (! $occupancy) {
                    $this->warn("  [SKIP] BED maintenance #{$mr->id}: không tìm thấy sinh viên.");
                    return;
                }

                $alreadyOccupied = Occupancy::occupiedBedsQuery()
                    ->where('bed_id', $log->old_bed_id)
                    ->where('id', '!=', $occupancy->id)
                    ->exists();

                if ($alreadyOccupied) {
                    $this->warn("  [SKIP] BED maintenance #{$mr->id}: giường cũ đã có sinh viên khác.");
                    return;
                }

                $temporaryBed  = Bed::query()->where('id', $occupancy->bed_id)->lockForUpdate()->first();
                $currentRoomId = (int) $occupancy->room_id;
                $currentBedId  = (int) $occupancy->bed_id;

                $occupancy->room_id = (int) $log->old_room_id;
                $occupancy->bed_id  = (int) $log->old_bed_id;
                $occupancy->save();

                if ($temporaryBed) {
                    $temporaryBed->status = 'active';
                    $temporaryBed->save();
                }
                $sourceBed->status = 'active';
                $sourceBed->save();

                DB::table('room_change_log')->insert([
                    'occupancy_id'           => $occupancy->id,
                    'old_room_id'            => $currentRoomId,
                    'old_bed_id'             => $currentBedId,
                    'new_room_id'            => (int) $log->old_room_id,
                    'new_bed_id'             => (int) $log->old_bed_id,
                    'transfer_reason'        => 'BED_MAINTENANCE_RETURN',
                    'change_type'            => 'TEMPORARY_MAINTENANCE',
                    'maintenance_request_id' => $mr->id,
                    'status'                 => 'RETURNED',
                    'change_source'          => 'admin',
                    'is_temporary'           => false,
                    'expected_return_date'   => null,
                    'transferred_at'         => now(),
                ]);
                DB::table('room_change_log')->where('id', $log->id)->update(['status' => 'RETURNED', 'completed_at' => now()]);

                MaintenanceRequest::query()->where('id', $mr->id)
                    ->update(['status' => 'COMPLETED', 'completed_at' => now()]);

                $student = $occupancy->student;
                if ($student) {
                    $oldRoomCode = ($room->floor?->building_code ?? '') . $room->room_number;
                    $title       = 'Bảo trì hoàn tất — Đã trả về giường cũ';
                    $content     = "Bảo trì giường đã hoàn tất. Bạn đã được chuyển về Giường {$sourceBed->bed_number} (Phòng {$oldRoomCode}).";
                    $this->saveNotification($student->id, $title, $content, 'bed_maintenance_return');
                    $pendingEmails[] = $this->buildPayload($student, $title, $content, 'bed_maintenance_return');
                }
            });

            foreach ($pendingEmails as $payload) {
                $this->sendEmail($payload);
            }

            $this->line("  [DONE] BED maintenance #{$mr->id} — bed_id={$mr->bed_id}");
            $this->completed++;
        } catch (\Throwable $e) {
            Log::error("[maintenance:auto-complete] BED #{$mr->id} thất bại: " . $e->getMessage());
            $this->error("  [FAIL] BED maintenance #{$mr->id}: " . $e->getMessage());
        }
    }

    private function returnRoomLog(object $log, array &$pendingEmails): void
    {
        $occupancy = Occupancy::occupiedBedsQuery()
            ->where('id', $log->occupancy_id)
            ->with('student')
            ->lockForUpdate()
            ->first();

        if (! $occupancy) {
            return;
        }

        $alreadyOccupied = Occupancy::occupiedBedsQuery()
            ->where('bed_id', $log->old_bed_id)
            ->where('id', '!=', $occupancy->id)
            ->exists();

        if ($alreadyOccupied) {
            $this->warn("  [SKIP] occupancy #{$occupancy->id}: giường cũ đã có sinh viên khác.");
            return;
        }

        $temporaryBed  = Bed::query()->where('id', $occupancy->bed_id)->lockForUpdate()->first();
        $oldBed        = Bed::query()->where('id', $log->old_bed_id)->lockForUpdate()->firstOrFail();
        $currentRoomId = (int) $occupancy->room_id;
        $currentBedId  = (int) $occupancy->bed_id;

        $occupancy->room_id = (int) $log->old_room_id;
        $occupancy->bed_id  = (int) $log->old_bed_id;
        $occupancy->save();

        if ($temporaryBed) {
            $temporaryBed->status = 'active';
            $temporaryBed->save();
        }
        $oldBed->status = 'active';
        $oldBed->save();

        DB::table('room_change_log')->insert([
            'occupancy_id'           => $occupancy->id,
            'old_room_id'            => $currentRoomId,
            'old_bed_id'             => $currentBedId,
            'new_room_id'            => (int) $log->old_room_id,
            'new_bed_id'             => (int) $log->old_bed_id,
            'transfer_reason'        => 'ROOM_MAINTENANCE_RETURN',
            'change_type'            => 'TEMPORARY_MAINTENANCE',
            'maintenance_request_id' => $log->maintenance_request_id ? (int) $log->maintenance_request_id : null,
            'status'                 => 'RETURNED',
            'change_source'          => 'admin',
            'is_temporary'           => false,
            'expected_return_date'   => null,
            'transferred_at'         => now(),
        ]);
        DB::table('room_change_log')->where('id', $log->id)->update(['status' => 'RETURNED', 'completed_at' => now()]);

        $student = $occupancy->student;
        if ($student) {
            $oldRoom     = Room::with('floor')->find($log->old_room_id);
            $oldRoomCode = ($oldRoom?->floor?->building_code ?? '') . ($oldRoom?->room_number ?? '');
            $title       = 'Bảo trì hoàn tất — Đã trả về phòng cũ';
            $content     = "Bảo trì phòng đã hoàn tất. Bạn đã được chuyển về Giường {$oldBed->bed_number} (Phòng {$oldRoomCode}).";
            $this->saveNotification($student->id, $title, $content, 'room_maintenance_return');
            $pendingEmails[] = $this->buildPayload($student, $title, $content, 'room_maintenance_return');

            // Thông báo admin
            $adminTitle   = "Bảo trì hoàn tất — Sinh viên trả về phòng {$oldRoomCode}";
            $adminContent = "Sinh viên {$student->full_name} ({$student->student_code}) đã được hệ thống tự động "
                          . "chuyển về Giường {$oldBed->bed_number} (Phòng {$oldRoomCode}) sau khi bảo trì hoàn tất.";
            $this->saveAdminNotification($adminTitle, $adminContent, 'room_maintenance_return', $log->maintenance_request_id ?? null);
            $pendingEmails[] = $this->buildAdminPayload($adminTitle, $adminContent, 'room_maintenance_return');
        }
    }

    private function saveAdminNotification(string $title, string $content, string $type, mixed $relatedId = null): void
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

    private function buildAdminPayload(string $title, string $content, ?string $type = null): array
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
            'type'         => $type,
        ];
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

    private function buildPayload(object $student, string $title, string $content, ?string $type = null): array
    {
        $name   = htmlspecialchars($student->full_name ?? '');
        $eTitle = htmlspecialchars($title);
        $eBody  = nl2br(htmlspecialchars($content));

        return [
            'email'      => $student->email ?? '',
            'name'       => $student->full_name ?? '',
            'subject'    => 'KTX — ' . $title,
            'body'       => "<div style='font-family:sans-serif;max-width:600px;margin:0 auto;padding:24px;color:#1f3152'>
                <h2 style='color:#244cb8;margin-top:0'>{$eTitle}</h2>
                <p>Xin chào <strong>{$name}</strong>,</p>
                <p>{$eBody}</p>
                <p style='color:#6b7280;font-size:12px;margin-top:32px'>Email này được gửi tự động bởi hệ thống quản lý KTX.</p>
            </div>",
            'type'       => $type,
            'student_id' => $student->id,
        ];
    }

    private function sendEmail(array $payload): void
    {
        // Admin bulk payload — queue để không dồn cục thời gian chạy lệnh cron.
        if (isset($payload['admin_emails'])) {
            foreach ($payload['admin_emails'] as $email) {
                try {
                    Mail::to($email)->queue(new GenericNotificationMail($payload['subject'], $payload['body']));
                } catch (\Exception $e) {
                    Log::error('Gửi email thông báo bảo trì (admin) thất bại', [
                        'type'  => $payload['type'] ?? null,
                        'email' => $email,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            return;
        }

        if (empty($payload['email'])) {
            return;
        }
        try {
            Mail::to($payload['email'], $payload['name'])->queue(new GenericNotificationMail($payload['subject'], $payload['body']));
        } catch (\Exception $e) {
            Log::error('Gửi email thông báo bảo trì thất bại', [
                'type'       => $payload['type'] ?? null,
                'student_id' => $payload['student_id'] ?? null,
                'email'      => $payload['email'],
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
