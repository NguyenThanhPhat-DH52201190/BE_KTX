<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class CheckStudentCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'student_code' => 'required|string',
        ];
    }

    public function messages(): array
    {
        return [
            'student_code.required' => 'Vui lòng nhập MSSV.',
            'student_code.string' => 'MSSV không hợp lệ.',
        ];
    }
}
