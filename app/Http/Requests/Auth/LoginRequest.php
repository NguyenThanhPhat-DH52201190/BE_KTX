<?php

namespace App\Http\Requests\Auth;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => 'required_without:student_code|email',
            'student_code' => 'required_without:email|string',
            'password' => 'required|string',
        ];
    }

    public function messages(): array
    {
        return [
            'email.required_without' => 'Vui lòng nhập email quản trị hoặc MSSV.',
            'email.email' => 'Email không hợp lệ.',
            'student_code.required_without' => 'Vui lòng nhập MSSV hoặc email quản trị.',
            'student_code.string' => 'MSSV không hợp lệ.',
            'password.required' => 'Vui lòng nhập mật khẩu.',
        ];
    }
}
