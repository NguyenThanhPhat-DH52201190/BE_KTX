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
        'stay_start_date',
        'stay_end_date',
        'status',
        'channel',
        'school_year',
        'semester',
        'bed_selection_days',
        'processing_days',
        'initial_payment_due_days',
    ];

    protected $casts = [
        'start_date'      => 'date:Y-m-d',
        'end_date'        => 'date:Y-m-d',
        'stay_start_date' => 'date:Y-m-d',
        'stay_end_date'   => 'date:Y-m-d',
        'bed_selection_days'       => 'integer',
        'processing_days'          => 'integer',
        'initial_payment_due_days' => 'integer',
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
