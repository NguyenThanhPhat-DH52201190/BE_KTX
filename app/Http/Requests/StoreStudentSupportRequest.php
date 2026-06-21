<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStudentSupportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email'             => ['required_without:student_id', 'nullable', 'email', 'exists:students,email'],
            'student_id'        => ['required_without:email', 'nullable', 'integer', 'exists:students,id'],
            'request_type'      => ['required', 'string', Rule::in([
                'room_change',
                'bed_change',
                'roommate_request',
                'complaint',
                'suggestion',
                'maintenance_report',
                'other',
            ])],
            'title'             => ['required', 'string', 'max:191'],
            'content'           => ['required', 'string'],
            'attachment_url'    => ['nullable', 'string', 'max:191'],
            'target_room_id'    => ['nullable', 'integer', 'exists:rooms,id'],
            'target_bed_id'     => ['nullable', 'integer', 'exists:beds,id'],
            'target_student_id' => ['nullable', 'integer', 'exists:students,id'],
        ];
    }
}
