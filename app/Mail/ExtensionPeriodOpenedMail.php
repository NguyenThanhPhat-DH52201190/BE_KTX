<?php

namespace App\Mail;

use App\Models\OccupancyPeriod;
use App\Models\Student;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ExtensionPeriodOpenedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly OccupancyPeriod $period,
        public readonly Student $student,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Thông báo: Đợt gia hạn lưu trú đã mở',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.extension-period-opened',
        );
    }
}
