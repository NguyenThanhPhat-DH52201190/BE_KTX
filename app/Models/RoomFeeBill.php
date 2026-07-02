<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoomFeeBill extends Model
{
    protected $table = 'room_fee_bills';

    protected $fillable = [
        'student_id',
        'occupancy_id',
        'month',
        'year',
        'amount',
        'original_amount',
        'discount_percent',
        'discount_amount',
        'discount_reason',
        'priority_criteria_id',
        'days_stayed',
        'total_days',
        'due_date',
        'payment_method',
        'transaction_code',
        'paid_at',
        'status',
        'admin_note',
        'exempted_by',
        'exempted_at',
    ];

    protected $casts = [
        'days_stayed'      => 'integer',
        'total_days'       => 'integer',
        'amount'           => 'integer',
        'original_amount'  => 'float',
        'discount_percent' => 'float',
        'discount_amount'  => 'float',
        'paid_at'          => 'datetime',
        'due_date'         => 'date',
        'exempted_at'      => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function occupancy(): BelongsTo
    {
        return $this->belongsTo(Occupancy::class);
    }
}
