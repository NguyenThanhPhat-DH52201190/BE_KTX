<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdmissionCandidate extends Model
{
    protected $table = 'admission_candidates';

    protected $fillable = [
        'admission_code',
        'admission_code_suffix',
        'expected_student_code',
        'full_name',
        'date_of_birth',
        'gender',
        'cccd',
        'phone',
        'email',
        'permanent_address',
        'major_code',
        'major_name',
        'course_year',
        'school_year',
        'status',
        'enrolled_at',
        'student_id',
    ];

    protected $casts = [
        'date_of_birth' => 'date:Y-m-d',
        'enrolled_at'   => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $model) {
            if ($model->isDirty('admission_code') && $model->admission_code) {
                preg_match('/(\d{5})$/', $model->admission_code, $matches);
                $model->admission_code_suffix = $matches[1] ?? null;
            }
        });
    }

    // expected_student_code KHÔNG còn được tự tính nữa (đã bỏ nút "Nhập học" đơn lẻ —
    // mục đích duy nhất của giá trị gợi ý này). MSSV giờ chỉ đến từ Excel trường gửi
    // (bulkEnroll()), không ai đọc cột này nữa. Cột vẫn giữ trong schema (cột chết),
    // chỉ dừng ghi giá trị mới để tránh nhầm là MSSV thật.

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function dormReservations(): HasMany
    {
        return $this->hasMany(DormReservation::class);
    }
}
