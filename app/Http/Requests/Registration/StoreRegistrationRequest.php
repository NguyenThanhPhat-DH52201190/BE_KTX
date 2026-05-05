<?php

namespace App\Http\Requests\Registration;

use App\Models\Account;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'student_code' => $this->input('student_code', $this->input('mssv')),
            'class_name' => $this->input('class_name', $this->input('class')),
            'parent_relationship' => $this->input('parent_relationship', $this->input('relationship')),
        ]);
    }

    public function rules(): array
    {
        $currentStudentId = $this->currentStudentId();

        return [
            'email' => ['required', 'email', 'exists:accounts,email'],
            'semester' => ['required', 'string', 'max:191'],
            'student_code' => [
                'required',
                'string',
                'max:191',
                Rule::unique('students', 'student_code')->ignore($currentStudentId),
            ],
            'full_name' => ['required', 'string', 'max:191'],
            'gender' => ['required', Rule::in(['male', 'female'])],
            'class_name' => ['required', 'string', 'max:191'],
            'faculty' => ['required', 'string', 'max:191'],
            'phone' => ['required', 'string', 'max:191'],
            'cccd' => [
                'required',
                'string',
                'max:191',
                Rule::unique('students', 'cccd')->ignore($currentStudentId),
            ],
            'permanent_address' => ['required', 'string', 'max:191'],
            'parent_name' => ['required', 'string', 'max:191'],
            'parent_phone' => ['required', 'string', 'max:191'],
            'parent_relationship' => ['required', 'string', 'max:191'],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'cccd_front' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'cccd_back' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ];
    }

    private function currentStudentId(): ?int
    {
        $email = $this->input('email');

        if (!$email) {
            return null;
        }

        $account = Account::query()
            ->select('student_id')
            ->where('email', $email)
            ->first();

        return $account?->student_id;
    }
}
