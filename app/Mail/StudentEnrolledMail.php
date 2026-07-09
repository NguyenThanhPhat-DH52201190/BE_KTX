<?php

namespace App\Mail;

use App\Models\Student;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class StudentEnrolledMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public readonly string $studentFullName;
    public readonly string $studentCode;
    public readonly ?string $studentClassName;
    public readonly ?string $studentFaculty;

    public function __construct(Student $student)
    {
        $this->studentFullName = (string) $student->full_name;
        $this->studentCode = (string) $student->student_code;
        $this->studentClassName = $student->class_name;
        $this->studentFaculty = $student->faculty;
    }

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
            with: [
                'studentFullName'  => $this->studentFullName,
                'studentCode'      => $this->studentCode,
                'studentClassName' => $this->studentClassName,
                'studentFaculty'   => $this->studentFaculty,
            ],
        );
    }
}
