<?php

namespace App\Http\Requests\Registration;

use App\Models\Student;
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
        $birthDate = $this->input('date_of_birth', $this->input('birthDate'));
        $cccdIssuedDate = $this->input('cccd_issued_date', $this->input('cccdIssueDate'));

        $this->merge([
            'student_code' => $this->input('student_code', $this->input('mssv')),
            'class_name' => $this->input('class_name', $this->input('class')),
            'faculty' => $this->input('faculty', $this->input('department')),
            'permanent_address' => $this->input('permanent_address', $this->input('address')),
            'date_of_birth' => $this->normalizeDate($birthDate),
            'cccd_issued_date' => $this->normalizeDate($cccdIssuedDate),
            'cccd_issued_place' => $this->input('cccd_issued_place', $this->input('cccdIssuePlace')),
            'course_year' => $this->input('course_year', $this->input('class')),
            'parent_relationship' => $this->input('parent_relationship', $this->input('relationship')),
        ]);
        // Ánh xạ các trường cha mẹ/gia đình theo quy ước đặt tên của frontend
        $this->merge([
            'father_name' => $this->input('father_name', $this->input('fatherName')),
            'father_birth_year' => $this->input('father_birth_year', $this->input('fatherBirthYear')),
            'father_job' => $this->input('father_job', $this->input('fatherJob')),
            'father_phone' => $this->input('father_phone', $this->input('fatherPhone')),
            'mother_name' => $this->input('mother_name', $this->input('motherName')),
            'mother_birth_year' => $this->input('mother_birth_year', $this->input('motherBirthYear')),
            'mother_job' => $this->input('mother_job', $this->input('motherJob')),
            'mother_phone' => $this->input('mother_phone', $this->input('motherPhone')),
            'parent_address' => $this->input('parent_address', $this->input('familyContactAddress')),
            'stay_from_date' => $this->normalizeDate($this->input('stay_from_date', $this->input('dormStartDate'))),
            'stay_to_date' => $this->normalizeDate($this->input('stay_to_date', $this->input('dormEndDate'))),
            'commitment_confirm' => $this->boolean('commitment_confirm', $this->input('commitment_confirmed')),
        ]);
    }

    public function rules(): array
    {
        $currentStudentId = $this->currentStudentId();

        return [
            'email' => ['required', 'email', 'exists:students,email'],
            'semester' => ['required', 'string', 'max:191'],
            'student_code' => [
                'required',
                'string',
                'max:191',
            ],
            'full_name' => ['required', 'string', 'max:191'],
            'date_of_birth' => ['required', 'date'],
            'gender' => ['required', Rule::in(['male', 'female'])],
            'class_name' => ['required', 'string', 'max:191'],
            'faculty' => ['required', 'string', 'max:191'],
            'course_year' => ['required', 'string', 'max:191'],
            'phone' => ['required', 'digits:10'],
            'cccd' => [
                'required',
                'digits:12',
                Rule::unique('students', 'cccd')->ignore($currentStudentId),
            ],
            'cccd_issued_date' => ['required', 'date'],
            'cccd_issued_place' => ['required', 'string', 'max:191'],
            'nationality' => ['required', 'string', 'max:191'],
            'ethnicity' => ['required', 'string', 'max:191'],
            'religion' => ['required', 'string', 'max:191'],
            'permanent_address' => ['required', 'string', 'max:191'],
            'parent_name' => ['required', 'string', 'max:191'],
            'parent_phone' => ['required', 'string', 'max:191'],
            'parent_relationship' => ['required', 'string', 'max:191'],
            'father_name' => ['required', 'string', 'max:191'],
            'father_birth_year' => ['nullable', 'string', 'max:191'],
            'father_job' => ['required', 'string', 'max:191'],
            'father_phone' => ['required', 'string', 'max:191'],
            'mother_name' => ['required', 'string', 'max:191'],
            'mother_birth_year' => ['nullable', 'string', 'max:191'],
            'mother_job' => ['required', 'string', 'max:191'],
            'mother_phone' => ['required', 'string', 'max:191'],
            'parent_address' => ['required', 'string', 'max:191'],
            'stay_from_date' => ['required', 'date'],
            'stay_to_date' => ['required', 'date', 'after_or_equal:stay_from_date'],
            'commitment_confirm' => ['required', 'accepted'],
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
            'date_of_birth.required' => 'Vui lòng nhập ngày sinh.',
            'date_of_birth.date' => 'Ngày sinh không hợp lệ.',
            'cccd_issued_date.required' => 'Vui lòng nhập ngày cấp CCCD.',
            'cccd_issued_date.date' => 'Ngày cấp CCCD không hợp lệ.',
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

        $student = Student::query()
            ->select('id')
            ->where('email', $email)
            ->first();

        return $student?->id;
    }

    private function normalizeDate($value): ?string
    {
        if (!$value || !is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        // Chấp nhận cả dd/mm/yyyy và yyyy-mm-dd từ frontend.
        if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $trimmed) === 1) {
            [$day, $month, $year] = explode('/', $trimmed);
            return sprintf('%04d-%02d-%02d', (int) $year, (int) $month, (int) $day);
        }

        return $trimmed;
    }
}
