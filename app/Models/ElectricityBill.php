<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ElectricityBill extends Model
{
    protected $table = 'electricity_bills';

    protected $fillable = [
        'student_id',
        'electricity_record_id',
        'occupancy_id',
        'month_year',
        'usage_kwh',
        'unit_price',
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
        'total_days' => 'integer',
    ];

    public function electricityRecord(): BelongsTo
    {
        return $this->belongsTo(ElectricityRecord::class);
    }

    public function occupancy(): BelongsTo
    {
        return $this->belongsTo(Occupancy::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
