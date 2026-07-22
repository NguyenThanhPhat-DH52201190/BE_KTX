<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Student extends Model
{
     protected $table = 'students';

    protected $fillable = [
        'student_code',
        'full_name',
        'date_of_birth',
        'gender',
        'class_name',
        'faculty',
        'course_year',
        'phone',
        'email',
        'cccd',
        'cccd_issued_date',
        'cccd_issued_place',
        'nationality',
        'ethnicity',
        'religion',
        'permanent_address',
        'province_code',
        'avatar',
        'aws_face_id',
        'status',
        'academic_status',
        'current_year',
        'father_name',
        'father_birth_year',
        'father_job',
        'father_phone',
        'mother_name',
        'mother_birth_year',
        'mother_job',
        'mother_phone',
        'parent_address',
        'emergency_contact_name',
        'emergency_contact_phone',
        'emergency_contact_relationship',
    ];

    

    public function registrations(): HasMany
    {
        return $this->hasMany(Registration::class);
    }

    public function priorities(): HasMany
    {
        return $this->hasMany(StudentPriority::class);
    }

    public function account(): HasOne
    {
        return $this->hasOne(Account::class, 'student_id');
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class, 'province_code', 'code');
    }
}
