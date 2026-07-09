<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreStudentSupportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Danh tính sinh viên lấy từ $request->user() (route đã bảo vệ auth:sanctum +
            // role:student), không nhận email/student_id từ client nữa.
            'title'          => ['required', 'string', 'max:191'],
            'content'        => ['required', 'string'],
            'attachment_url' => ['nullable', 'string', 'max:191'],
        ];
    }
}
