<?php

namespace App\Services;

use App\Mail\GenericNotificationMail;
use App\Models\Notification;
use App\Models\Student;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Central place for "trạng thái đơn thay đổi -> báo cho sinh viên" flows:
 * sinh viên đã có tài khoản (MSSV) nhận cả chuông thông báo lẫn email;
 * tân sinh viên (chưa có tài khoản, ví dụ hồ sơ giữ chỗ) chỉ nhận email
 * vì hệ thống chưa có tài khoản để gắn chuông thông báo.
 */
class StudentNotificationService
{
    /**
     * @param bool $queue Đưa vào hàng đợi thay vì gửi ngay — bật khi gọi hàm này trong vòng
     *                    lặp nhiều sinh viên cùng lúc (VD duyệt hàng loạt theo đợt), để không
     *                    chặn request chờ gửi email lần lượt từng người.
     */
    public function notifyStudent(Student $student, string $title, string $content, string $type, ?int $relatedId = null, bool $queue = false): void
    {
        $this->createBellNotification($student->id, $title, $content, $type, $relatedId, true);
        $this->sendEmail($student->email, $student->full_name, $title, $content, $type, $student->id, $queue);
    }

    public function notifyStudentWebOnly(Student $student, string $title, string $content, string $type, ?int $relatedId = null): void
    {
        $this->createBellNotification($student->id, $title, $content, $type, $relatedId, false);
    }

    public function notifyEmailOnly(?string $email, ?string $name, string $title, string $content, bool $queue = false): void
    {
        $this->sendEmail($email, $name, $title, $content, null, null, $queue);
    }

    private function createBellNotification(int $studentId, string $title, string $content, string $type, ?int $relatedId, bool $sendEmail): void
    {
        try {
            $notification = Notification::create([
                'student_id'  => $studentId,
                'title'       => $title,
                'content'     => $content,
                'type'        => $type,
                'related_id'  => $relatedId,
                'target_type' => 'individual',
                'send_email'  => $sendEmail,
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

    private function sendEmail(?string $email, ?string $name, string $title, string $content, ?string $type = null, ?int $studentId = null, bool $queue = false): void
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
            $subject = "KTX — {$title}";

            if ($queue) {
                Mail::to($email, $name ?? '')->queue(new GenericNotificationMail($subject, $body));
            } else {
                Mail::send([], [], function ($message) use ($email, $name, $subject, $body) {
                    $message->to($email, $name ?? '')
                        ->subject($subject)
                        ->html($body);
                });
            }
        } catch (\Throwable $e) {
            Log::error('Gửi email thông báo thất bại', [
                'type'       => $type,
                'student_id' => $studentId,
                'email'      => $email,
                'title'      => $title,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
