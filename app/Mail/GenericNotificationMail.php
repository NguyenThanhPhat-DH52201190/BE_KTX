<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Mailable dùng chung cho các thông báo email dạng "tiêu đề + nội dung HTML dựng sẵn"
 * (StudentNotificationService, MaintenanceController, StudentSupportRequestController...).
 * Trước đây các nơi này tự gửi bằng Mail::send([], [], function ($m) {...}) — không thể
 * ->queue() một closure, nên gộp thành 1 Mailable để chỗ nào cần gửi hàng loạt có thể queue.
 * Nội dung email giữ nguyên y hệt (subject + HTML body được truyền vào, không đổi gì).
 */
class GenericNotificationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $mailSubject,
        public readonly string $htmlBody,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->mailSubject);
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->htmlBody);
    }
}
