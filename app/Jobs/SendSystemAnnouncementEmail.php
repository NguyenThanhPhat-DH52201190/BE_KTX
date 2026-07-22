<?php

namespace App\Jobs;

use App\Mail\GenericNotificationMail;
use App\Models\SystemAnnouncementRecipient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendSystemAnnouncementEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $recipientId) {}

    public function handle(): void
    {
        $recipient = SystemAnnouncementRecipient::with(['announcement', 'student'])->find($this->recipientId);
        if (! $recipient || ! $recipient->announcement || ! $recipient->student) {
            return;
        }

        $student = $recipient->student;
        if (empty($student->email)) {
            $recipient->update(['email_status' => 'skipped', 'email_error' => null]);
            return;
        }

        $announcement = $recipient->announcement;
        $safeName = htmlspecialchars($student->full_name ?? '');
        $safeTitle = htmlspecialchars($announcement->title);
        $safeContent = nl2br(htmlspecialchars($announcement->content));
        $body = "
            <div style='font-family:sans-serif;max-width:600px;margin:0 auto;padding:24px;color:#1f3152;'>
                <h2 style='color:#244cb8;margin-top:0;'>{$safeTitle}</h2>
                <p>Xin chào <strong>{$safeName}</strong>,</p>
                <p style='line-height:1.7;'>{$safeContent}</p>
                <p style='color:#6b7280;font-size:12px;margin-top:32px;'>Email này được gửi tự động bởi hệ thống quản lý KTX.</p>
            </div>
        ";

        try {
            Mail::to($student->email, $student->full_name ?? '')
                ->send(new GenericNotificationMail('KTX - ' . $announcement->title, $body));

            $recipient->update([
                'email_status'  => 'sent',
                'email_sent_at' => now(),
                'email_error'   => null,
            ]);
        } catch (\Throwable $e) {
            $recipient->update([
                'email_status' => 'failed',
                'email_error'  => $e->getMessage(),
            ]);

            Log::error('Gửi email thông báo hệ thống thất bại', [
                'recipient_id' => $recipient->id,
                'student_id'   => $student->id,
                'error'        => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        SystemAnnouncementRecipient::where('id', $this->recipientId)->update([
            'email_status' => 'failed',
            'email_error'  => $exception->getMessage(),
        ]);
    }
}
