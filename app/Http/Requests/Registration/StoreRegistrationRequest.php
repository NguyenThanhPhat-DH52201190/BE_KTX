<?php

namespace App\Http\Requests\Registration;

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
        $birthDate      = $this->input('date_of_birth', $this->input('birthDate'));
        $cccdIssuedDate = $this->input('cccd_issued_date', $this->input('cccdIssueDate'));

        $this->merge([
            'student_code'      => $this->input('student_code', $this->input('mssv')),
            'class_name'        => $this->input('class_name', $this->input('class')),
            'faculty'           => $this->input('faculty', $this->input('department')),
            'permanent_address' => $this->input('permanent_address', $this->input('address')),
            'province_code'     => $this->input('province_code', $this->input('provinceCode')),
            'date_of_birth'     => $this->normalizeDate($birthDate),
            'cccd_issued_date'  => $this->normalizeDate($cccdIssuedDate),
            'cccd_issued_place' => $this->input('cccd_issued_place', $this->input('cccdIssuePlace')),
            'course_year'       => $this->input('course_year', $this->input('class')),
            'parent_relationship' => $this->input('parent_relationship', $this->input('relationship')),
        ]);

        $this->merge([
            'father_name'       => $this->input('father_name', $this->input('fatherName')),
            'father_birth_year' => $this->input('father_birth_year', $this->input('fatherBirthYear')),
            'father_job'        => $this->input('father_job', $this->input('fatherJob')),
            'father_phone'      => $this->input('father_phone', $this->input('fatherPhone')) ?: null,
            'mother_name'       => $this->input('mother_name', $this->input('motherName')),
            'mother_birth_year' => $this->input('mother_birth_year', $this->input('motherBirthYear')),
            'mother_job'        => $this->input('mother_job', $this->input('motherJob')),
            'mother_phone'      => $this->input('mother_phone', $this->input('motherPhone')) ?: null,
            'parent_address'    => $this->input('parent_address', $this->input('familyContactAddress')),
            'stay_from_date'    => $this->normalizeDate($this->input('stay_from_date', $this->input('dormStartDate'))),
            'stay_to_date'      => $this->normalizeDate($this->input('stay_to_date', $this->input('dormEndDate'))),
            'commitment_confirm' => $this->boolean('commitment_confirm', $this->input('commitment_confirmed')),
            'priority_criteria_ids' => $this->normalizePriorityCriteriaIds($this->input('priority_criteria_ids')),
        ]);
    }

    public function rules(): array
    {
        $currentStudentId = $this->currentStudentId();

        // Regex chỉ chứa chữ cái (Unicode, kể cả tiếng Việt có dấu) và khoảng trắng
        $nameRegex = 'regex:/^[\pL\s]+$/u';

        return [
            // ── Tài khoản ──────────────────────────────────────────────────
            'email'        => ['required', 'email', 'exists:students,email'],
            'semester'     => ['nullable', 'string', 'max:191'],
            'student_code' => ['required', 'string', 'max:191'],

            // ── Thông tin cá nhân ──────────────────────────────────────────
            'full_name'    => ['required', 'string', 'min:2', 'max:100', $nameRegex],
            'date_of_birth' => ['required', 'date', 'before:today', 'after:1900-01-01'],
            'gender'       => ['required', Rule::in(['male', 'female'])],
            'class_name'   => ['required', 'string', 'max:191'],
            'faculty'      => ['required', 'string', 'max:191'],
            'course_year'  => ['required', 'string', 'max:191'],
            'phone'        => ['required', 'string', 'regex:/^(0[0-9]{9})$/'],
            'cccd'         => [
                'required',
                'string',
                'regex:/^[0-9]{12}$/',
                Rule::unique('students', 'cccd')->ignore($currentStudentId),
            ],
            'cccd_issued_date'  => ['required', 'date'],
            'cccd_issued_place' => ['required', 'string', 'max:191'],
            'nationality'       => ['required', 'string', 'max:191'],
            'ethnicity'         => ['required', 'string', 'max:191'],
            'religion'          => ['required', 'string', 'max:191'],
            'permanent_address' => ['required', 'string', 'max:191'],
            'province_code'     => ['nullable', 'exists:provinces,code'],

            // ── Thông tin cha ──────────────────────────────────────────────
            'father_name'       => ['nullable', 'string', 'min:2', 'max:100', $nameRegex],
            'father_birth_year' => ['nullable', 'string', 'max:191'],
            'father_job'        => ['required', 'string', 'max:191'],
            'father_phone'      => ['nullable', 'string', 'regex:/^(0[0-9]{9})$/'],

            // ── Thông tin mẹ ───────────────────────────────────────────────
            'mother_name'       => ['nullable', 'string', 'min:2', 'max:100', $nameRegex],
            'mother_birth_year' => ['nullable', 'string', 'max:191'],
            'mother_job'        => ['required', 'string', 'max:191'],
            'mother_phone'      => ['nullable', 'string', 'regex:/^(0[0-9]{9})$/'],

            // ── Liên hệ khẩn / người giám hộ ─────────────────────────────
            'parent_name'         => ['required', 'string', 'max:191'],
            'parent_phone'        => ['required', 'string', 'regex:/^(0[0-9]{9})$/'],
            'parent_relationship' => ['required', 'string', 'max:191'],
            'parent_address'      => ['required', 'string', 'max:191'],

            // ── Thời gian lưu trú ──────────────────────────────────────────
            'stay_from_date' => ['nullable', 'date'],
            'stay_to_date'   => ['nullable', 'date'],

            // ── Cam kết ────────────────────────────────────────────────────
            'commitment_confirm' => ['required', 'accepted'],

            // ── Ảnh hồ sơ (required, tối đa 5 MB) ────────────────────────
            'avatar'     => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'cccd_front' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'cccd_back'  => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],

            // ── Tiêu chí ưu tiên ───────────────────────────────────────────
            'priority_criteria_ids'   => ['nullable', 'array'],
            'priority_criteria_ids.*' => [
                'integer',
                Rule::exists('priority_criteria', 'id')->where('is_active', true),
            ],
            'evidence_files'   => ['nullable', 'array'],
            'evidence_files.*' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf,webp', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            // full_name
            'full_name.required' => 'Vui lòng nhập họ tên.',
            'full_name.min'      => 'Họ tên phải có ít nhất 2 ký tự.',
            'full_name.max'      => 'Họ tên không được vượt quá 100 ký tự.',
            'full_name.regex'    => 'Họ tên chỉ được chứa chữ cái và khoảng trắng.',

            // date_of_birth
            'date_of_birth.required' => 'Vui lòng nhập ngày sinh.',
            'date_of_birth.date'     => 'Ngày sinh không hợp lệ.',
            'date_of_birth.before'   => 'Ngày sinh phải trước ngày hôm nay.',
            'date_of_birth.after'    => 'Ngày sinh phải sau năm 1900.',

            // gender
            'gender.required' => 'Vui lòng chọn giới tính.',
            'gender.in'       => 'Giới tính không hợp lệ.',

            // phone
            'phone.required' => 'Vui lòng nhập số điện thoại.',
            'phone.regex'    => 'Số điện thoại phải là 10 chữ số bắt đầu bằng 0.',

            // cccd
            'cccd.required' => 'Vui lòng nhập số CCCD.',
            'cccd.regex'    => 'Số CCCD phải gồm đúng 12 chữ số.',
            'cccd.unique'   => 'Số CCCD đã tồn tại trong hệ thống.',

            // cccd dates
            'cccd_issued_date.required' => 'Vui lòng nhập ngày cấp CCCD.',
            'cccd_issued_date.date'     => 'Ngày cấp CCCD không hợp lệ.',
            'cccd_issued_place.required' => 'Vui lòng nhập nơi cấp CCCD.',

            // parent / emergency contact
            'parent_name.required'         => 'Vui lòng nhập tên người liên hệ.',
            'parent_phone.required'        => 'Vui lòng nhập số điện thoại liên hệ khẩn.',
            'parent_phone.regex'           => 'Số điện thoại liên hệ phải là 10 chữ số bắt đầu bằng 0.',
            'parent_relationship.required' => 'Vui lòng nhập quan hệ với người liên hệ.',
            'parent_address.required'      => 'Vui lòng nhập địa chỉ người liên hệ.',

            // father
            'father_name.min'   => 'Tên cha phải có ít nhất 2 ký tự.',
            'father_name.max'   => 'Tên cha không được vượt quá 100 ký tự.',
            'father_name.regex' => 'Tên cha chỉ được chứa chữ cái và khoảng trắng.',
            'father_job.required' => 'Vui lòng nhập nghề nghiệp của cha.',
            'father_phone.regex' => 'Số điện thoại cha phải là 10 chữ số bắt đầu bằng 0.',

            // mother
            'mother_name.min'   => 'Tên mẹ phải có ít nhất 2 ký tự.',
            'mother_name.max'   => 'Tên mẹ không được vượt quá 100 ký tự.',
            'mother_name.regex' => 'Tên mẹ chỉ được chứa chữ cái và khoảng trắng.',
            'mother_job.required' => 'Vui lòng nhập nghề nghiệp của mẹ.',
            'mother_phone.regex' => 'Số điện thoại mẹ phải là 10 chữ số bắt đầu bằng 0.',

            // photos
            'avatar.required'      => 'Vui lòng tải lên ảnh thẻ.',
            'avatar.file'          => 'Ảnh thẻ không hợp lệ.',
            'avatar.mimes'         => 'Ảnh thẻ phải là định dạng JPG, JPEG, PNG hoặc WEBP.',
            'avatar.max'           => 'Ảnh thẻ không được vượt quá 5 MB.',
            'cccd_front.required'  => 'Vui lòng tải lên ảnh CCCD mặt trước.',
            'cccd_front.file'      => 'Ảnh CCCD mặt trước không hợp lệ.',
            'cccd_front.mimes'     => 'Ảnh CCCD mặt trước phải là định dạng JPG, JPEG, PNG hoặc WEBP.',
            'cccd_front.max'       => 'Ảnh CCCD mặt trước không được vượt quá 5 MB.',
            'cccd_back.required'   => 'Vui lòng tải lên ảnh CCCD mặt sau.',
            'cccd_back.file'       => 'Ảnh CCCD mặt sau không hợp lệ.',
            'cccd_back.mimes'      => 'Ảnh CCCD mặt sau phải là định dạng JPG, JPEG, PNG hoặc WEBP.',
            'cccd_back.max'        => 'Ảnh CCCD mặt sau không được vượt quá 5 MB.',

            // priority criteria
            'priority_criteria_ids.array'    => 'Tiêu chí ưu tiên phải là danh sách.',
            'priority_criteria_ids.*.integer' => 'ID tiêu chí ưu tiên không hợp lệ.',
            'priority_criteria_ids.*.exists'  => 'Tiêu chí ưu tiên không tồn tại hoặc không còn hiệu lực.',
            'evidence_files.array'            => 'File minh chứng phải là danh sách.',
            'evidence_files.*.file'           => 'File minh chứng không hợp lệ.',
            'evidence_files.*.mimes'          => 'File minh chứng phải là JPG, JPEG, PNG, PDF hoặc WEBP.',
            'evidence_files.*.max'            => 'Mỗi file minh chứng không được vượt quá 5 MB.',

            // commitment
            'commitment_confirm.required' => 'Vui lòng xác nhận cam kết.',
            'commitment_confirm.accepted' => 'Bạn phải đồng ý với các điều khoản cam kết.',
        ];
    }

    // Dùng student_id của tài khoản đang đăng nhập (route đã bảo vệ auth:sanctum +
    // role:student) để loại trừ khỏi các rule unique — không dùng email client gửi,
    // tránh trường hợp submit email của sinh viên khác làm sai lệch kiểm tra trùng lặp.
    private function currentStudentId(): ?int
    {
        return $this->user()?->student_id;
    }

    private function normalizeDate(mixed $value): ?string
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

    private function normalizePriorityCriteriaIds(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            return array_values(array_filter($value, fn ($id) => $id !== null && $id !== ''));
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (is_array($decoded)) {
                return array_values(array_filter($decoded, fn ($id) => $id !== null && $id !== ''));
            }
        }

        return null;
    }
}
