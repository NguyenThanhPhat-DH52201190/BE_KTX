<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Occupancy extends Model
{
    protected $table = 'occupancy';

    public $timestamps = false;

    protected $fillable = [
        'registration_id',
        'student_id',
        'room_id',
        'bed_id',
        'check_in_date',
        'check_out_date',
        'status',
        'bed_approval_status',
        'reason',
        'previous_occupancy_id',
    ];

    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function bed(): BelongsTo
    {
        return $this->belongsTo(Bed::class);
    }

    public function checkoutRequests(): HasMany
    {
        return $this->hasMany(CheckoutRequest::class);
    }

    /**
     * Yêu cầu thôi ở đang chờ duyệt (thay cho cột cờ checkout_requested cũ).
     */
    public function pendingCheckoutRequest(): HasOne
    {
        return $this->hasOne(CheckoutRequest::class)->where('status', 'pending');
    }
}
