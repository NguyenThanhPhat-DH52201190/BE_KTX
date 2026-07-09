<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentSupportRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => (int) $this->id,
            'student_id'     => (int) $this->student_id,
            'title'          => $this->title,
            'content'        => $this->content,
            'attachment_url' => $this->attachment_url,
            'status'         => $this->status,
            'admin_note'     => $this->admin_note,
            'created_at'     => $this->created_at?->toISOString(),
            'updated_at'     => $this->updated_at?->toISOString(),
            'student' => $this->whenLoaded('student', fn () => $this->student ? [
                'id'           => (int) $this->student->id,
                'student_code' => $this->student->student_code ?? '',
                'full_name'    => $this->student->full_name ?? '',
                'email'        => $this->student->email ?? '',
                'phone'        => $this->student->phone ?? '',
                'class_name'   => $this->student->class_name ?? '',
                'faculty'      => $this->student->faculty ?? '',
            ] : null),
        ];
    }
}
