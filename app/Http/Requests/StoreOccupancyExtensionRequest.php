<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOccupancyExtensionRequest extends FormRequest
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
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Vui lòng nhập lý do gia hạn.',
            'reason.min'      => 'Lý do phải có ít nhất 10 ký tự.',
        ];
    }
}
