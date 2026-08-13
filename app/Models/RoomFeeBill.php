<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoomFeeBill extends Model
{
    protected $table = 'room_fee_bills';

    protected $fillable = [
        'student_id',
        'occupancy_id',
        'month',
        'year',
        'months_count',
        'amount',
        'original_amount',
        'discount_percent',
        'discount_amount',
        'discount_reason',
        'priority_criteria_id',
        'days_stayed',
        'total_days',
        'due_date',
        'payment_method',
        'transaction_code',
        'paid_at',
        'status',
        'admin_note',
        'exempted_by',
        'exempted_at',
    ];

    protected $casts = [
        'months_count'     => 'integer',
        'days_stayed'      => 'integer',
        'total_days'       => 'integer',
        'amount'           => 'integer',
        'original_amount'  => 'float',
        'discount_percent' => 'float',
        'discount_amount'  => 'float',
        'paid_at'          => 'datetime',
        'due_date'         => 'date',
        'exempted_at'      => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function occupancy(): BelongsTo
    {
        return $this->belongsTo(Occupancy::class);
    }

    /**
     * Lọc các hóa đơn có khoảng bao phủ [month/year, month/year + months_count - 1]
     * chứa tháng/năm truyền vào — dùng thay cho so khớp `month`/`year` chính xác,
     * vì từ khi có hóa đơn gộp nhiều tháng (quý), 1 hóa đơn có thể bao phủ nhiều
     * tháng khác với tháng ghi trên cột `month`.
     */
    public function scopeCoveringMonth(Builder $query, int $month, int $year): Builder
    {
        $targetIndex = $year * 12 + $month;

        return $query
            ->whereRaw('(year * 12 + month) <= ?', [$targetIndex])
            ->whereRaw('(year * 12 + month + months_count - 1) >= ?', [$targetIndex]);
    }

    /**
     * Hóa đơn có số tiền phải trả <= 0 (miễn 100% qua chính sách ưu tiên/giảm giá) KHÔNG
     * BAO GIỜ được mang status unpaid/overdue — nếu không, sinh viên nợ 0đ vẫn bị hệ thống
     * nhắc nợ/buộc thôi ở oan (báo cáo 14/08: sinh viên diện con liệt sĩ/thương binh bị
     * blacklist dù không nợ đồng nào, vì bill giảm 100% chỉ chỉnh amount=0 mà không đổi
     * status). Dùng hàm này ở MỌI nơi tạo/sửa amount của RoomFeeBill, không tự set status
     * rải rác từng chỗ để tránh lặp lại đúng lỗi này ở 1 điểm mới sau này.
     */
    public static function resolveStatus(int $finalAmount, string $fallbackStatus): string
    {
        return $finalAmount <= 0 ? 'exempted' : $fallbackStatus;
    }
}
