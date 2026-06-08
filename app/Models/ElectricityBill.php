<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ElectricityBill extends Model
{
    protected $table = 'electricity_bills';

    protected $fillable = [
        'student_id',
        'registration_id',
        'month_year',
        'usage_kwh',
        'unit_price',
        'amount',
        'due_date',
        'payment_method',
        'transaction_code',
        'paid_at',
        'status',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }
}
