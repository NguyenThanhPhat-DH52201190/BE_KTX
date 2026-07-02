<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\Student;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Central place for "trạng thái đơn thay đổi -> báo cho sinh viên" flows:
 * sinh viên đã có tài khoản (MSSV) nhận cả chuông thông báo lẫn email;
 * tân sinh viên (chưa có tài khoản, ví dụ hồ sơ giữ chỗ) chỉ nhận email
 * vì hệ thống chưa có tài khoản để gắn chuông thông báo.
 */
class StudentNotificationService
{
    public function notifyStudent(Student $student, string $title, string $content, string $type, ?int $relatedId = null): void
    {
        $this->createBellNotification($student->id, $title, $content, $type, $relatedId);
        $this->sendEmail($student->email, $student->full_name, $title, $content);
    }

    public function notifyEmailOnly(?string $email, ?string $name, string $title, string $content): void
    {
        $this->sendEmail($email, $name, $title, $content);
    }

    private function createBellNotification(int $studentId, string $title, string $content, string $type, ?int $relatedId): void
    {
        try {
            $notification = Notification::create([
                'student_id'  => $studentId,
                'title'       => $title,
                'content'     => $content,
                'type'        => $type,
                'related_id'  => $relatedId,
                'target_type' => 'individual',
                'send_email'  => true,
            ]);

            DB::table('notification_recipient')->insert([
                'notification_id' => $notification->id,
                'student_id'      => $studentId,
                'is_read'         => false,
                'read_at'         => null,
            ]);
        } catch (\Throwable) {
            // Không chặn luồng chính nếu ghi chuông thông báo lỗi.
        }
    }

    private function sendEmail(?string $email, ?string $name, string $title, string $content): void
    {
        if (empty($email)) {
            return;
        }

        try {
            $safeName = htmlspecialchars($name ?? '');
            $safeTitle = htmlspecialchars($title);
            $safeContent = nl2br(htmlspecialchars($content));
            $body = "
                <div style='font-family:sans-serif;max-width:600px;margin:0 auto;padding:24px;color:#1f3152;'>
                    <h2 style='color:#244cb8;margin-top:0;'>{$safeTitle}</h2>
                    <p>Xin chào <strong>{$safeName}</strong>,</p>
                    <p style='line-height:1.7;'>{$safeContent}</p>
                    <p style='color:#6b7280;font-size:12px;margin-top:32px;'>Email này được gửi tự động bởi hệ thống quản lý KTX.</p>
                </div>
            ";

            Mail::send([], [], function ($message) use ($email, $name, $title, $body) {
                $message->to($email, $name ?? '')
                    ->subject("KTX — {$title}")
                    ->html($body);
            });
        } catch (\Throwable) {
            // Không chặn luồng chính nếu gửi mail lỗi.
        }
    }
}
