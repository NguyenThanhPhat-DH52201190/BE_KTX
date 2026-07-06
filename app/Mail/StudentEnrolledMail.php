<?php

namespace App\Mail;

use App\Models\Student;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class StudentEnrolledMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Student $student,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Thông báo xác nhận nhập học — Hướng dẫn đăng ký KTX',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.student-enrolled',
        );
    }
}
