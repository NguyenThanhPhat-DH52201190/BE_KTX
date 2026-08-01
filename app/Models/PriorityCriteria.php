<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PriorityCriteria extends Model
{
    protected $table = 'priority_criteria';

    protected $fillable = [
        'code',
        'name',
        'description',
        'priority_score',
        'is_active',
        'tier',
    ];

    protected $casts = [
        'priority_score' => 'integer',
        'is_active' => 'boolean',
        'tier' => 'integer',
    ];

    public function studentPriorities(): HasMany
    {
        return $this->hasMany(StudentPriority::class, 'priority_criteria_id');
    }

    public function feeDiscountPolicy(): HasOne
    {
        return $this->hasOne(FeeDiscountPolicy::class, 'priority_criteria_id');
    }
}
