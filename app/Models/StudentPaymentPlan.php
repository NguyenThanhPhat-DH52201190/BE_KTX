<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentPaymentPlan extends Model
{
    protected $table = 'student_payment_plans';

    protected $fillable = [
        'student_id',
        'type',
        'is_active',
        'discount_percent',
        'reason',
        'support_request_id',
        'activated_at',
        'deactivated_at',
        'created_by',
        'deactivated_by',
    ];

    protected $casts = [
        'is_active'         => 'boolean',
        'discount_percent'  => 'float',
        'activated_at'      => 'datetime',
        'deactivated_at'    => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function supportRequest(): BelongsTo
    {
        return $this->belongsTo(StudentSupportRequest::class, 'support_request_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }
}
