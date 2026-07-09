<?php

namespace App\Services;

use App\Jobs\SendSystemAnnouncementEmail;
use App\Models\Floor;
use App\Models\Notification;
use App\Models\Occupancy;
use App\Models\Room;
use App\Models\Student;
use App\Models\SystemAnnouncement;
use App\Models\SystemAnnouncementRecipient;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SystemAnnouncementService
{
    public function send(SystemAnnouncement $announcement): SystemAnnouncement
    {
        if (in_array($announcement->status, ['sending', 'sent'], true)) {
            return $announcement;
        }

        $students = $this->resolveRecipients($announcement);
        if ($students->isEmpty()) {
            $announcement->update(['status' => 'failed']);
            throw new \RuntimeException('Không tìm thấy sinh viên phù hợp với đối tượng nhận.');
        }

        $pendingEmailRecipientIds = DB::transaction(function () use ($announcement, $students) {
            $announcement->update(['status' => 'sending']);

            SystemAnnouncementRecipient::where('system_announcement_id', $announcement->id)->delete();

            $notification = null;
            if ($announcement->send_web) {
                $notification = Notification::create([
                    'student_id'  => null,
                    'title'       => $announcement->title,
                    'content'     => $announcement->content,
                    'type'        => $announcement->type,
                    'related_id'  => $announcement->id,
                    'target_type' => $announcement->target_type,
                    'send_email'  => $announcement->send_email,
                ]);

                $students->chunk(1000)->each(function (Collection $chunk) use ($notification) {
                    DB::table('notification_recipient')->insert(
                        $chunk->map(fn (Student $student) => [
                            'notification_id' => $notification->id,
                            'student_id'      => $student->id,
                            'is_read'         => false,
                            'read_at'         => null,
                        ])->all(),
                    );
                });
            }

            $now = now();
            $students->chunk(1000)->each(function (Collection $chunk) use ($announcement, $notification, $now) {
                SystemAnnouncementRecipient::insert(
                    $chunk->map(fn (Student $student) => [
                        'system_announcement_id' => $announcement->id,
                        'student_id'              => $student->id,
                        'notification_id'          => $notification?->id,
                        'email_status'             => $announcement->send_email
                            ? ($student->email ? 'pending' : 'skipped')
                            : 'skipped',
                        'email_sent_at'            => null,
                        'email_error'              => null,
                        'created_at'               => $now,
                        'updated_at'               => $now,
                    ])->all(),
                );
            });

            $announcement->update([
                'status'  => 'sent',
                'sent_at' => $now,
            ]);

            if (! $announcement->send_email) {
                return [];
            }

            return SystemAnnouncementRecipient::where('system_announcement_id', $announcement->id)
                ->where('email_status', 'pending')
                ->pluck('id')
                ->all();
        });

        foreach ($pendingEmailRecipientIds as $recipientId) {
            try {
                SendSystemAnnouncementEmail::dispatch((int) $recipientId);
            } catch (\Throwable $e) {
                SystemAnnouncementRecipient::where('id', $recipientId)->update([
                    'email_status' => 'failed',
                    'email_error'  => $e->getMessage(),
                ]);
                Log::error('Không thể đưa email thông báo vào hàng đợi', [
                    'recipient_id' => $recipientId,
                    'error'        => $e->getMessage(),
                ]);
            }
        }

        return $announcement->fresh();
    }

    public function resendFailedEmails(SystemAnnouncement $announcement): int
    {
        if (! $announcement->send_email) {
            return 0;
        }

        $recipientIds = SystemAnnouncementRecipient::where('system_announcement_id', $announcement->id)
            ->whereIn('email_status', ['failed', 'pending'])
            ->pluck('id');

        foreach ($recipientIds as $recipientId) {
            SystemAnnouncementRecipient::where('id', $recipientId)->update([
                'email_status' => 'pending',
                'email_error'  => null,
            ]);
            SendSystemAnnouncementEmail::dispatch((int) $recipientId);
        }

        return $recipientIds->count();
    }

    /**
     * @return Collection<int, Student>
     */
    private function resolveRecipients(SystemAnnouncement $announcement): Collection
    {
        $query = Student::query()->whereIn('id', $this->recipientStudentIds($announcement));

        return $query->orderBy('student_code')->get();
    }

    private function recipientStudentIds(SystemAnnouncement $announcement): array
    {
        $targetValue = $this->decodeTargetValue($announcement->target_value);

        $occupancyQuery = Occupancy::query()
            ->where('status', 'ACTIVE')
            ->whereNotNull('student_id');

        if ($announcement->target_type === 'building') {
            $buildingCode = (string) $targetValue;
            $occupancyQuery->whereHas('room.floor', fn ($query) => $query->where('building_code', $buildingCode));
        }

        if ($announcement->target_type === 'floor') {
            $floorId = (int) $targetValue;
            $occupancyQuery->whereHas('room', fn ($query) => $query->where('floor_id', $floorId));
        }

        if ($announcement->target_type === 'room') {
            $roomId = (int) $targetValue;
            $occupancyQuery->where('room_id', $roomId);
        }

        if ($announcement->target_type === 'students') {
            return collect((array) $targetValue)
                ->map(fn ($id) => (int) $id)
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        return $occupancyQuery
            ->pluck('student_id')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function decodeTargetValue(?string $targetValue): mixed
    {
        if ($targetValue === null || $targetValue === '') {
            return null;
        }

        $decoded = json_decode($targetValue, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $targetValue;
    }

    public function normalizeTargetValue(string $targetType, mixed $targetValue): ?string
    {
        if ($targetType === 'active_students') {
            return null;
        }

        if ($targetType === 'students') {
            return json_encode(collect((array) $targetValue)->map(fn ($id) => (int) $id)->filter()->unique()->values()->all());
        }

        return $targetValue === null ? null : (string) $targetValue;
    }

    public function targetLabel(SystemAnnouncement $announcement): string
    {
        $value = $this->decodeTargetValue($announcement->target_value);

        return match ($announcement->target_type) {
            'building' => 'Tòa ' . $value,
            'floor' => optional(Floor::find((int) $value), fn (Floor $floor) => 'Tòa ' . $floor->building_code . ' - Tầng ' . $floor->floor_number) ?? 'Tầng #' . $value,
            'room' => optional(Room::with('floor')->find((int) $value), fn (Room $room) => ($room->floor?->building_code ?? '') . $room->room_number) ?? 'Phòng #' . $value,
            'students' => count((array) $value) . ' sinh viên',
            default => 'Tất cả sinh viên đang ở KTX',
        };
    }
}
