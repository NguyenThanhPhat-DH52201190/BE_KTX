<?php

namespace App\Mail;

use App\Models\OccupancyPeriod;
use App\Models\Student;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ExtensionPeriodOpenedMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public readonly string $periodName;
    public readonly string $periodEndDateLabel;
    public readonly string $studentName;

    public function __construct(OccupancyPeriod $period, Student $student)
    {
        $this->periodName = (string) $period->name;
        $this->periodEndDateLabel = $period->end_date ? $period->end_date->format('d/m/Y') : 'Xem trong hệ thống';
        $this->studentName = (string) $student->full_name;
    }

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
            with: [
                'periodName'         => $this->periodName,
                'periodEndDateLabel' => $this->periodEndDateLabel,
                'studentName'        => $this->studentName,
            ],
        );
    }
}
