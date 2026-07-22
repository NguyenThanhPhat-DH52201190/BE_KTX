<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StoreSystemAnnouncementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'title'           => ['required', 'string', 'max:255'],
            'content'         => ['required', 'string'],
            'type'            => ['required', 'string', Rule::in(['general', 'warning', 'urgent', 'reminder', 'policy'])],
            'priority'        => ['required', 'string', Rule::in(['normal', 'important', 'urgent'])],
            'target_type'     => ['required', 'string', Rule::in(['active_students', 'building', 'floor', 'room', 'students'])],
            'target_value'    => ['nullable'],
            'send_web'        => ['required', 'boolean'],
            'send_email'      => ['required', 'boolean'],
            'attachment_path' => ['nullable', 'string', 'max:255'],
            'scheduled_at'    => ['nullable', 'date'],
            'action'          => ['nullable', 'string', Rule::in(['draft', 'send', 'schedule'])],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (! $this->boolean('send_web') && ! $this->boolean('send_email')) {
                $validator->errors()->add('send_web', 'Phải chọn ít nhất một kênh gửi.');
            }

            $targetType = (string) $this->input('target_type');
            $targetValue = $this->input('target_value');

            if (in_array($targetType, ['building', 'floor', 'room'], true) && blank($targetValue)) {
                $validator->errors()->add('target_value', 'Vui lòng chọn đối tượng nhận.');
            }

            if ($targetType === 'students' && (! is_array($targetValue) || count($targetValue) === 0)) {
                $validator->errors()->add('target_value', 'Vui lòng chọn sinh viên nhận thông báo.');
            }

            if (! blank($targetValue) && $targetType === 'building' && ! DB::table('buildings')->where('building_code', (string) $targetValue)->exists()) {
                $validator->errors()->add('target_value', 'Tòa được chọn không tồn tại.');
            }

            if (! blank($targetValue) && $targetType === 'floor' && ! DB::table('floors')->where('id', (int) $targetValue)->exists()) {
                $validator->errors()->add('target_value', 'Tầng được chọn không tồn tại.');
            }

            if (! blank($targetValue) && $targetType === 'room' && ! DB::table('rooms')->where('id', (int) $targetValue)->exists()) {
                $validator->errors()->add('target_value', 'Phòng được chọn không tồn tại.');
            }

            if ($targetType === 'students' && is_array($targetValue)) {
                $studentIds = collect($targetValue)->map(fn ($id) => (int) $id)->filter()->unique()->values();
                if ($studentIds->count() === 0 || DB::table('students')->whereIn('id', $studentIds)->count() !== $studentIds->count()) {
                    $validator->errors()->add('target_value', 'Danh sách sinh viên nhận thông báo không hợp lệ.');
                }
            }

            if ($this->input('action') === 'schedule' && blank($this->input('scheduled_at'))) {
                $validator->errors()->add('scheduled_at', 'Vui lòng chọn thời gian gửi.');
            }

            if ($this->input('action') === 'schedule' && filled($this->input('scheduled_at')) && strtotime((string) $this->input('scheduled_at')) <= now()->timestamp) {
                $validator->errors()->add('scheduled_at', 'Thời gian gửi phải lớn hơn thời gian hiện tại.');
            }
        });
    }
}
