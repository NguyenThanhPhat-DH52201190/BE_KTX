<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentSupportRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => (int) $this->id,
            'student_id'        => (int) $this->student_id,
            'request_type'      => $this->request_type,
            'title'             => $this->title,
            'content'           => $this->content,
            'attachment_url'    => $this->attachment_url,
            'status'            => $this->status,
            'admin_note'        => $this->admin_note,
            'target_room_id'    => $this->target_room_id ? (int) $this->target_room_id : null,
            'target_bed_id'     => $this->target_bed_id ? (int) $this->target_bed_id : null,
            'target_student_id' => $this->target_student_id ? (int) $this->target_student_id : null,
            'created_at'        => $this->created_at?->toISOString(),
            'updated_at'        => $this->updated_at?->toISOString(),
            'student' => $this->whenLoaded('student', fn () => $this->student ? [
                'id'           => (int) $this->student->id,
                'student_code' => $this->student->student_code ?? '',
                'full_name'    => $this->student->full_name ?? '',
                'email'        => $this->student->email ?? '',
                'phone'        => $this->student->phone ?? '',
                'class_name'   => $this->student->class_name ?? '',
                'faculty'      => $this->student->faculty ?? '',
            ] : null),
            'target_room' => $this->whenLoaded('targetRoom', fn () => $this->targetRoom ? [
                'id'           => (int) $this->targetRoom->id,
                'room_number'  => (string) $this->targetRoom->room_number,
                'building_code' => $this->targetRoom->floor?->building_code ?? '',
                'floor_number' => (int) ($this->targetRoom->floor?->floor_number ?? 0),
            ] : null),
            'target_bed' => $this->whenLoaded('targetBed', fn () => $this->targetBed ? [
                'id'         => (int) $this->targetBed->id,
                'bed_number' => (string) $this->targetBed->bed_number,
            ] : null),
            'target_student' => $this->whenLoaded('targetStudent', fn () => $this->targetStudent ? [
                'id'           => (int) $this->targetStudent->id,
                'full_name'    => $this->targetStudent->full_name ?? '',
                'student_code' => $this->targetStudent->student_code ?? '',
                'email'        => $this->targetStudent->email ?? '',
            ] : null),
        ];
    }
}
