<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Registration extends Model
{
    protected $table = 'registrations';

    protected $fillable = [
        'student_id',
        'semester',
        'school_year',
        'fathers_name',
        'fathers_birth_year',
        'fathers_job',
        'fathers_phone',
        'mothers_name',
        'mothers_birth_year',
        'mothers_job',
        'mothers_phone',
        'parent_address',
        'stay_from_date',
        'stay_to_date',
        'cccd_front_url',
        'cccd_back_url',
        'commitment_confirm',
        'status',
        'note',
        'reason',
        'assigned_bed_id',
        'assigned_room_id',
        'approved_at',

    ];


    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}