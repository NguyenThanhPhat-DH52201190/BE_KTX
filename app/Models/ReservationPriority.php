<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReservationPriority extends Model
{
    protected $table = 'reservation_priorities';

    protected $fillable = [
        'dorm_reservation_id',
        'priority_criteria_id',
        'status',
        'verified_by',
        'verified_at',
    ];

    protected $casts = [
        'verified_at' => 'datetime',
    ];

    public function dormReservation(): BelongsTo
    {
        return $this->belongsTo(DormReservation::class, 'dorm_reservation_id');
    }

    public function criteria(): BelongsTo
    {
        return $this->belongsTo(PriorityCriteria::class, 'priority_criteria_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'verified_by');
    }

    public function evidences(): HasMany
    {
        return $this->hasMany(ReservationPriorityEvidence::class, 'reservation_priority_id');
    }
}
