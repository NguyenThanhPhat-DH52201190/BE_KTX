<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Registration extends Model
{
    protected $table = 'registrations';

    protected $fillable = [
        'student_id',
        'avatar_url',
        'form_data',
        'semester',
        'school_year',
        'father_name',
        'father_birth_year',
        'father_job',
        'father_phone',
        'mother_name',
        'mother_birth_year',
        'mother_job',
        'mother_phone',
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