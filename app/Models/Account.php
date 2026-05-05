<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\HasApiTokens;

class Account extends Model
{
    use HasApiTokens;
    protected $table = 'accounts';

    protected $fillable = [
        'student_id',
        'email',
        'password',
        'role',
        'is_active',
        'otp_code',
        'otp_expire',
    ];

    protected $hidden = [
        'password',
        'otp_code',
    ];

    public $timestamps = false;

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }
}
