<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeeDiscountPolicy extends Model
{
    protected $table = 'fee_discount_policies';

    protected $fillable = [
        'priority_criteria_id',
        'discount_percent',
        'is_active',
    ];

    protected $casts = [
        'discount_percent' => 'float',
        'is_active'        => 'boolean',
    ];

    public function criteria(): BelongsTo
    {
        return $this->belongsTo(PriorityCriteria::class, 'priority_criteria_id');
    }
}
