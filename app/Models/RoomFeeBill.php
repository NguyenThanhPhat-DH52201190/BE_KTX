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
        'days_stayed',
        'total_days',
        'due_date',
        'payment_method',
        'transaction_code',
        'paid_at',
        'status',
    ];

    protected $casts = [
        'days_stayed' => 'integer',
        'total_days'  => 'integer',
        'amount'      => 'integer',
        'paid_at'     => 'datetime',
        'due_date'    => 'date',
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
