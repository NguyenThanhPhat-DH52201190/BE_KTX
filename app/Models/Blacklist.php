<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Blacklist extends Model
{
    protected $table = 'blacklist';

    protected $fillable = [
        'student_id',
        'reason',
        'source',
        'created_by',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'created_by');
    }

    /**
     * A student is banned (permanently) if any blacklist record exists.
     */
    public static function isBanned(int $studentId): bool
    {
        return static::where('student_id', $studentId)->exists();
    }
}
