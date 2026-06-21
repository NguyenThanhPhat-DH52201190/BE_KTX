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
            'email'      => ['nullable', 'string', 'email'],
            'student_id' => ['nullable', 'integer'],
            'reason'     => ['required', 'string', 'min:10', 'max:1000'],
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
