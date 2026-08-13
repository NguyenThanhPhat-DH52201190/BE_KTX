<?php

namespace App\Services;

use App\Models\Occupancy;
use App\Models\RoomFeeBill;
use App\Models\StudentPaymentPlan;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RoomFeeBillingService
{
    private const DEFAULT_PRICE = 350000;

    public function __construct(private readonly FeeDiscountService $discountService) {}

    // -------------------------------------------------------------------------
    // Activation flow (gọi bởi OccupancyObserver khi status -> ACTIVE)
    // -------------------------------------------------------------------------

    /**
     * Tạo hóa đơn khi occupancy chuyển sang ACTIVE — dùng cho luồng admin gán
     * giường trực tiếp (chưa có hóa đơn nào từ trước).
     *
     * Chỉ tạo ĐÚNG 1 hóa đơn, dùng chung công thức quý với createInitialBill()
     * (xem createQuarterBundleBill()) — không tự bù cho các quý trước đó nếu
     * kích hoạt bị trễ; nếu việc kích hoạt trễ qua tới quý sau, quý đó để
     * generateQuarterlyBills() (cron đầu quý) tự lo như mọi occupancy ACTIVE
     * khác, không cần xử lý đặc biệt ở đây.
     */
    public function createBillsOnActivation(Occupancy $occupancy): void
    {
        if (! $occupancy->check_in_date) {
            Log::warning("[RoomFeeBilling] Occupancy #{$occupancy->id} thiếu check_in_date — bỏ qua.");
            return;
        }

        $checkIn = Carbon::parse($occupancy->check_in_date)->startOfDay();
        $dueDate = Carbon::today()->addDays(7)->toDateString();

        $this->createQuarterBundleBill($occupancy, $checkIn, $dueDate);
    }

    /**
     * Tạo hóa đơn ban đầu khi sinh viên chọn giường.
     *
     * Quy tắc (thu theo quý cố định Q1=T1-T3, Q2=T4-T6, Q3=T7-T9, Q4=T10-T12):
     * - Hóa đơn đầu = 1 hóa đơn GỘP đủ số tháng còn lại của quý hiện tại, tính
     *   từ THÁNG check-in (không tính theo ngày lẻ — vào ngày nào trong tháng
     *   cũng tính đủ nguyên tháng đó) tới hết quý. Không tạo hóa đơn quý kế
     *   tiếp ngay — quý sau sẽ do generateQuarterlyBills() (cron đầu quý) đảm nhiệm.
     * - Idempotent: trả về bill đã bao phủ tháng check-in nếu đã tồn tại.
     * due_date của hóa đơn đầu = hôm nay + initial_payment_due_days.
     */
    public function createInitialBill(Occupancy $occupancy): ?RoomFeeBill
    {
        if (! $occupancy->check_in_date) {
            return null;
        }

        $dueDays = $occupancy->registration?->period?->initial_payment_due_days;
        if (! $dueDays) {
            return null;
        }

        $checkIn = Carbon::parse($occupancy->check_in_date)->startOfDay();
        $dueDate = Carbon::today('Asia/Ho_Chi_Minh')->addDays($dueDays)->toDateString();

        return $this->createQuarterBundleBill($occupancy, $checkIn, $dueDate);
    }

    /**
     * Tạo (hoặc trả về nếu đã tồn tại) 1 hóa đơn gộp đủ số tháng còn lại của
     * quý chứa $checkIn — dùng chung bởi createInitialBill() và
     * createBillsOnActivation(), đảm bảo 2 đường luôn ra cùng 1 kết quả cho
     * cùng 1 tháng check-in, chỉ khác thời điểm được gọi và due_date.
     */
    private function createQuarterBundleBill(Occupancy $occupancy, Carbon $checkIn, string $dueDate): ?RoomFeeBill
    {
        $month = $checkIn->month;
        $year  = $checkIn->year;

        $existing = RoomFeeBill::where('student_id', $occupancy->student_id)
            ->coveringMonth($month, $year)
            ->first();

        if ($existing) {
            return $existing;
        }

        $monthsCount   = $this->monthsRemainingInQuarter($month);
        $pricePerMonth = $this->getRoomFeePerMonth();

        return $this->createBundleBill($occupancy, $month, $year, $monthsCount, $pricePerMonth, $dueDate);
    }

    /**
     * Số tháng còn lại của quý (Q1=T1-T3, Q2=T4-T6, Q3=T7-T9, Q4=T10-T12) tính
     * từ tháng truyền vào (bao gồm chính tháng đó) tới hết quý.
     * VD: tháng 7 (đầu Q3) -> 3; tháng 2 (giữa Q1) -> 2; tháng 3 (cuối Q1) -> 1.
     */
    private function monthsRemainingInQuarter(int $month): int
    {
        $positionInQuarter = ($month - 1) % 3; // 0, 1 hoặc 2

        return 3 - $positionInQuarter;
    }

    /**
     * Cộng thêm $count tháng vào [$month, $year], trả về [month, year] mới.
     */
    private function addMonths(int $month, int $year, int $count): array
    {
        $total = ($year * 12 + ($month - 1)) + $count;

        return [$total % 12 + 1, intdiv($total, 12)];
    }

    // -------------------------------------------------------------------------
    // Scheduler flow (gọi bởi GenerateQuarterlyRoomFeeBillsCommand đầu mỗi quý)
    // -------------------------------------------------------------------------

    /**
     * Tạo hóa đơn quý (gộp đủ 3 tháng) cho tất cả occupancy đang ACTIVE, tại
     * tháng đầu quý ($month phải là 1, 4, 7 hoặc 10 — do command gọi hàm này
     * đảm nhiệm việc chặn tháng không hợp lệ).
     *
     * Quy tắc: chỉ lọc occupancy status=ACTIVE — occupancy PENDING_PAYMENT quá
     * hạn đã bị ExpireInitialPaymentCommand tự hủy từ trước, nên tới thời điểm
     * cron quý chạy, occupancy còn tồn tại và ACTIVE nghĩa là đã từng kích hoạt
     * hợp lệ. Occupancy đang nợ hóa đơn quý trước vẫn được tạo hóa đơn quý mới
     * bình thường (không chờ trả hết nợ cũ) — nợ quá hạn do
     * ProcessOverdueBillsCommand xử lý riêng.
     *
     * @return array{generated: int, skipped: int}
     */
    public function generateQuarterlyBills(int $month, int $year): array
    {
        $generated     = 0;
        $skipped       = 0;
        $pricePerMonth = $this->getRoomFeePerMonth();
        $billingStart  = Carbon::create($year, $month, 1)->startOfDay();
        // Hạn thanh toán = ngày hóa đơn được sinh ra (hôm nay, lúc cron/lệnh này chạy) + 7 ngày —
        // không còn cố định "ngày 20" như trước (dễ gây hiểu lầm với ngày cron chạy 01 đầu quý).
        $dueDate       = Carbon::today()->addDays(7)->toDateString();

        // Sinh viên đang bật "đóng theo tháng" (installment) phải tiếp tục được
        // bill riêng từng tháng — không gộp quý, nếu không sẽ phá vỡ đúng chế
        // độ họ đã được admin duyệt. Hạn của từng bill tháng (bên dưới) CỐ TÌNH
        // vẫn giữ "ngày 20 của chính tháng đó" (không đổi theo rule +7 ở trên) —
        // vì đây là lịch trả góp trải đều theo từng tháng, không phải "hạn 7 ngày
        // sau khi tạo" (nếu đổi, cả 3 tháng sẽ cùng đến hạn trong 1 tuần, phá vỡ
        // mục đích trả góp).
        $installmentStudentIds = StudentPaymentPlan::query()
            ->where('type', 'installment')
            ->where('is_active', true)
            ->pluck('student_id')
            ->flip();

        Occupancy::where('status', 'ACTIVE')
            ->whereNotNull('check_in_date')
            ->each(function (Occupancy $occupancy) use ($month, $year, $billingStart, $pricePerMonth, $dueDate, $installmentStudentIds, &$generated, &$skipped) {
                $checkIn = Carbon::parse($occupancy->check_in_date)->startOfDay();

                // Bỏ qua nếu sinh viên chưa đến quý này (sẽ được createInitialBill/
                // createBillsOnActivation lo hóa đơn quý cụt khi họ thực sự vào ở).
                if ($checkIn->copy()->startOfMonth()->gt($billingStart)) {
                    $skipped++;
                    return;
                }

                if ($installmentStudentIds->has($occupancy->student_id)) {
                    $createdAny = false;
                    [$m, $y] = [$month, $year];
                    for ($i = 0; $i < 3; $i++) {
                        if (! $this->billExists($occupancy->student_id, $m, $y)) {
                            $this->createBundleBill($occupancy, $m, $y, 1, $pricePerMonth, Carbon::create($y, $m, 20)->toDateString());
                            $createdAny = true;
                        }
                        [$m, $y] = $this->addMonths($m, $y, 1);
                    }
                    $createdAny ? $generated++ : $skipped++;
                    return;
                }

                if ($this->billExists($occupancy->student_id, $month, $year)) {
                    $skipped++;
                    return;
                }

                $this->createBundleBill($occupancy, $month, $year, 3, $pricePerMonth, $dueDate);
                $generated++;
            });

        return ['generated' => $generated, 'skipped' => $skipped];
    }

    // -------------------------------------------------------------------------
    // Installment flow (gọi khi admin bật chế độ "đóng theo tháng" cho sinh viên)
    // -------------------------------------------------------------------------

    /**
     * Tách bill gộp nhiều tháng (months_count > 1) đang unpaid/overdue của sinh
     * viên thành từng bill 1 tháng riêng — số bill tách ra = đúng months_count
     * của bill gộp đó (có thể là 2 hoặc 3, không cố định 3). Không lỗi nếu
     * không có bill gộp nào đang chờ — bật installment vẫn hợp lệ, chỉ ảnh
     * hưởng các lần sinh bill sau này.
     *
     * @return array{split: bool, bills?: array<RoomFeeBill>}
     */
    public function splitQuarterlyBillToMonthly(int $studentId): array
    {
        $quarterlyBill = RoomFeeBill::where('student_id', $studentId)
            ->where('months_count', '>', 1)
            ->whereIn('status', ['unpaid', 'overdue'])
            ->first();

        if (! $quarterlyBill) {
            return ['split' => false];
        }

        $occupancy   = Occupancy::find($quarterlyBill->occupancy_id);
        $totalAmount = (int) round((float) ($quarterlyBill->original_amount ?? $quarterlyBill->amount));
        $monthsCount = $quarterlyBill->months_count;
        $monthlyBase = intdiv($totalAmount, $monthsCount);

        $months = [];
        $month  = (int) $quarterlyBill->month;
        $year   = (int) $quarterlyBill->year;
        for ($i = 0; $i < $monthsCount; $i++) {
            $months[] = [$month, $year];
            $month++;
            if ($month > 12) {
                $month = 1;
                $year++;
            }
        }

        $newBills = [];

        DB::transaction(function () use ($quarterlyBill, $occupancy, $months, $monthsCount, $monthlyBase, $totalAmount, &$newBills) {
            $status          = $quarterlyBill->status;
            $originalDueDate = $quarterlyBill->due_date;
            $studentId       = $quarterlyBill->student_id;
            $occupancyId     = $quarterlyBill->occupancy_id;

            $quarterlyBill->delete();

            foreach ($months as $index => [$m, $y]) {
                $isLast       = $index === $monthsCount - 1;
                $baseForMonth = $isLast ? ($totalAmount - $monthlyBase * ($monthsCount - 1)) : $monthlyBase;

                $discount = $occupancy
                    ? $this->discountService->calculate($occupancy, $baseForMonth)
                    : [
                        'original_amount'  => $baseForMonth,
                        'discount_percent' => 0.0,
                        'discount_amount'  => 0,
                        'final_amount'     => $baseForMonth,
                        'discount_reason'  => null,
                    ];

                $dueDate      = $index === 0 ? $originalDueDate : Carbon::create($y, $m, 20)->toDateString();
                $totalDays    = Carbon::create($y, $m, 1)->daysInMonth;
                $monthStatus  = RoomFeeBill::resolveStatus($discount['final_amount'], $status);

                $newBills[] = RoomFeeBill::create([
                    'student_id'       => $studentId,
                    'occupancy_id'     => $occupancyId,
                    'month'            => $m,
                    'year'             => $y,
                    'months_count'     => 1,
                    'amount'           => $discount['final_amount'],
                    'original_amount'  => $discount['discount_percent'] > 0 ? $discount['original_amount'] : null,
                    'discount_percent' => $discount['discount_percent'] > 0 ? $discount['discount_percent'] : null,
                    'discount_amount'  => $discount['discount_amount'],
                    'discount_reason'  => $discount['discount_reason'],
                    'days_stayed'      => $totalDays,
                    'total_days'       => $totalDays,
                    'due_date'         => $dueDate,
                    'status'           => $monthStatus,
                    'exempted_at'      => $monthStatus === 'exempted' ? Carbon::now() : null,
                ]);
            }
        });

        return ['split' => true, 'bills' => $newBills];
    }

    // -------------------------------------------------------------------------
    // Overdue flow (gọi bởi UpdateOverdueBillsCommand hàng ngày)
    // -------------------------------------------------------------------------

    /**
     * Chuyển hóa đơn unpaid đã quá due_date sang overdue.
     *
     * @return int Số hóa đơn được cập nhật
     */
    public function markOverdueBills(): int
    {
        // Lưới an toàn: hóa đơn amount<=0 (miễn 100%) không bao giờ được chuyển sang overdue,
        // dù lỡ status vẫn đang là unpaid do sót ở nơi tạo/sửa amount (xem RoomFeeBill::resolveStatus).
        return RoomFeeBill::where('status', 'unpaid')
            ->where('amount', '>', 0)
            ->where('due_date', '<', Carbon::today()->toDateString())
            ->update(['status' => 'overdue']);
    }

    // -------------------------------------------------------------------------
    // Payment flow
    // -------------------------------------------------------------------------

    /**
     * Ghi nhận thanh toán thành công.
     */
    public function markAsPaid(RoomFeeBill $bill, string $paymentMethod, string $transactionCode): RoomFeeBill
    {
        $bill->update([
            'status'           => 'paid',
            'paid_at'          => Carbon::now(),
            'payment_method'   => $paymentMethod,
            'transaction_code' => $transactionCode,
        ]);

        return $bill->fresh();
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Đọc đơn giá phòng từ settings, fallback về DEFAULT_PRICE nếu chưa cấu hình.
     */
    private function getRoomFeePerMonth(): int
    {
        $value = DB::table('settings')
            ->where('key', 'room_fee_per_month')
            ->value('value');

        $parsed = (int) $value;

        return $parsed > 0 ? $parsed : self::DEFAULT_PRICE;
    }

    /**
     * Tạo 1 hóa đơn gộp $monthsCount tháng liên tiếp, bắt đầu từ $startMonth/
     * $startYear. $monthsCount = 1 là hóa đơn tháng bình thường (total_days =
     * số ngày trong tháng, giữ nguyên quy ước cũ để không ảnh hưởng tới
     * `is_quarterly` flag). $monthsCount > 1 là hóa đơn gộp nhiều tháng
     * (total_days = null — đúng quy ước "is_quarterly" đã dùng sẵn ở
     * RoomFeeBillController/StudentPaymentController).
     */
    private function createBundleBill(
        Occupancy $occupancy,
        int $startMonth,
        int $startYear,
        int $monthsCount,
        int $pricePerMonth,
        string $dueDate,
    ): RoomFeeBill {
        $baseAmount = $pricePerMonth * $monthsCount;
        $discount   = $this->discountService->calculate($occupancy, $baseAmount);
        $isSingleMonth = $monthsCount === 1;
        $totalDays  = $isSingleMonth ? Carbon::create($startYear, $startMonth, 1)->daysInMonth : null;
        $status     = RoomFeeBill::resolveStatus($discount['final_amount'], 'unpaid');

        return RoomFeeBill::create([
            'student_id'       => $occupancy->student_id,
            'occupancy_id'     => $occupancy->id,
            'month'            => $startMonth,
            'year'             => $startYear,
            'months_count'     => $monthsCount,
            'amount'           => $discount['final_amount'],
            'original_amount'  => $discount['original_amount'],
            'discount_percent' => $discount['discount_percent'],
            'discount_amount'  => $discount['discount_amount'],
            'discount_reason'  => $discount['discount_reason'],
            'days_stayed'      => $totalDays,
            'total_days'       => $totalDays,
            'due_date'         => $dueDate,
            'status'           => $status,
            'exempted_at'      => $status === 'exempted' ? Carbon::now() : null,
        ]);
    }

    /**
     * Kiểm tra tháng/năm này đã nằm trong khoảng bao phủ của 1 hóa đơn nào của
     * sinh viên chưa (hóa đơn 1 tháng hay hóa đơn gộp nhiều tháng đều tính).
     */
    private function billExists(int $studentId, int $month, int $year): bool
    {
        return RoomFeeBill::where('student_id', $studentId)
            ->coveringMonth($month, $year)
            ->exists();
    }
}
