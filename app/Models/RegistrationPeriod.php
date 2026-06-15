<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RegistrationPeriod extends Model
{
    protected $table = 'registration_periods';

    protected $fillable = [
        'name',
        'start_date',
        'end_date',
        'status',
        'channel',
        'school_year',
        'semester',
        'bed_selection_days',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'bed_selection_days' => 'integer',
    ];

    public function registrations(): HasMany
    {
        return $this->hasMany(Registration::class);
    }

    public function waitlist(): HasMany
    {
        return $this->hasMany(Waitlist::class);
    }
}
