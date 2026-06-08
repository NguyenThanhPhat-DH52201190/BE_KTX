<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoomFeeBill extends Model
{
    protected $table = 'room_fee_bills';

    protected $fillable = [
        'student_id',
        'registration_id',
        'quarter',
        'year',
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
