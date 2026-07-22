<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Models\RoomFeeBill;

class Occupancy extends Model
{
    protected $table = 'occupancy';

    public $timestamps = false;

    public const OCCUPIED_BED_STATUSES = ['ROOM_CONFIRMED', 'PENDING_PAYMENT', 'ACTIVE'];

    public const OCCUPIED_BED_APPROVAL_STATUSES = ['pending', 'approved'];

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

    public static function occupiedBedsQuery(): Builder
    {
        return static::query()->occupiedBeds();
    }

    public function scopeOccupiedBeds(Builder $query): Builder
    {
        return $query
            ->whereNotNull('bed_id')
            ->whereIn('status', self::OCCUPIED_BED_STATUSES)
            ->whereIn('bed_approval_status', self::OCCUPIED_BED_APPROVAL_STATUSES);
    }

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

    public function roomFeeBills(): HasMany
    {
        return $this->hasMany(RoomFeeBill::class);
    }

    public function extensions(): HasMany
    {
        return $this->hasMany(OccupancyExtension::class);
    }
}
