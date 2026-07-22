<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CheckoutRequest extends Model
{
    protected $table = 'checkout_requests';

    protected $fillable = [
        'occupancy_id',
        'student_id',
        'reason',
        'expected_leave_date',
        'status',
        'rejection_reason',
        'processed_by',
        'processed_at',
    ];

    protected $casts = [
        'expected_leave_date' => 'date',
        'processed_at' => 'datetime',
    ];

    public function occupancy(): BelongsTo
    {
        return $this->belongsTo(Occupancy::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'processed_by');
    }
}
