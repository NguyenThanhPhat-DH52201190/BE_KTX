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
        $currentAccountId = $this->currentAccountId();

        return [
            'email' => ['required', 'email', 'exists:accounts,email'],
            'semester' => ['required', 'string', 'max:191'],
            'student_code' => [
                'required',
                'string',
                'max:191',
                // student_code now unique on accounts
                Rule::unique('accounts', 'student_code')->ignore($currentAccountId),
            ],
            'full_name' => ['required', 'string', 'max:191'],
            'gender' => ['required', Rule::in(['male', 'female'])],
            'class_name' => ['required', 'string', 'max:191'],
            'faculty' => ['required', 'string', 'max:191'],
            'phone' => ['required', 'digits:10'],
            'cccd' => [
                'required',
                'digits:12',
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

    public function messages(): array
    {
        return [
            'phone.required' => 'Vui lòng nhập số điện thoại.',
            'phone.digits' => 'Số điện thoại phải gồm đúng 10 chữ số.',
            'cccd.required' => 'Vui lòng nhập số CCCD.',
            'cccd.digits' => 'Số CCCD phải gồm đúng 12 chữ số.',
            'cccd.unique' => 'Số CCCD đã tồn tại.',
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

    private function currentAccountId(): ?int
    {
        $email = $this->input('email');

        if (!$email) {
            return null;
        }

        $account = Account::query()
            ->select('id')
            ->where('email', $email)
            ->first();

        return $account?->id;
    }
}
