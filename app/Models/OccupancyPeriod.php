<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OccupancyPeriod extends Model
{
    protected $table = 'occupancy_periods';

    protected $fillable = [
        'name',
        'start_date',
        'end_date',
        'extension_until_date',
        'status',
        'description',
    ];

    protected $casts = [
        'start_date'           => 'date:Y-m-d',
        'end_date'             => 'date:Y-m-d',
        'extension_until_date' => 'date:Y-m-d',
    ];

    public function extensions(): HasMany
    {
        return $this->hasMany(OccupancyExtension::class);
    }

    /**
     * Hạn chót nộp đơn gia hạn THẬT — LUÔN là 17:00 của end_date, không phải 23:59:59/00:00.
     * Dùng chung công thức với RegistrationPeriod::admissionDeadline() (đợt tuyển sinh) để nhất
     * quán cách chốt "hạn chót" trên toàn hệ thống — sinh viên đã quen kiểu chốt giờ 17h này.
     */
    public function applicationDeadline(): ?\Carbon\Carbon
    {
        if (!$this->end_date) {
            return null;
        }

        return \Carbon\Carbon::parse($this->end_date)->setTime(17, 0, 0);
    }
}
