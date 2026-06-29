<?php

namespace App\Services;

use App\Models\Occupancy;
use App\Models\RoomFeeBill;
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
     * Tạo hóa đơn khi occupancy chuyển sang ACTIVE.
     *
     * Quy trình:
     * 1. Tạo hóa đơn prorated cho tháng check-in (nếu vào giữa tháng).
     * 2. Nếu check_in_date nằm trong quá khứ, tạo thêm hóa đơn đầy đủ
     *    cho các tháng bị bỏ lỡ từ tháng check-in đến tháng hiện tại.
     * 3. Bỏ qua nếu hóa đơn đã tồn tại (student_id + month + year).
     */
    public function createBillsOnActivation(Occupancy $occupancy): void
    {
        if (! $occupancy->check_in_date) {
            Log::warning("[RoomFeeBilling] Occupancy #{$occupancy->id} thiếu check_in_date — bỏ qua.");
            return;
        }

        $pricePerMonth = $this->getRoomFeePerMonth();
        $checkIn       = Carbon::parse($occupancy->check_in_date)->startOfDay();
        $currentMonth  = Carbon::now()->startOfMonth();
        $checkInMonth  = $checkIn->copy()->startOfMonth();

        // Duyệt từ tháng check-in đến tháng hiện tại.
        // Nếu check_in_date nằm trong tương lai, ceiling là tháng check-in đó
        // để đảm bảo hóa đơn tháng đầu luôn được tạo ngay khi ACTIVE.
        $ceiling = $checkInMonth->gt($currentMonth) ? $checkInMonth : $currentMonth;
        $cursor  = $checkInMonth->copy();

        while ($cursor->lte($ceiling)) {
            $month = $cursor->month;
            $year  = $cursor->year;

            if (! $this->billExists($occupancy->student_id, $month, $year)) {
                if ($cursor->isSameMonth($checkIn)) {
                    $this->createProratedBill($occupancy, $checkIn, $pricePerMonth);
                } else {
                    $this->createFullMonthBill($occupancy, $month, $year, $pricePerMonth);
                }
            }

            $cursor->addMonthNoOverflow();
        }
    }

    /**
     * Tạo hóa đơn ban đầu khi sinh viên chọn giường.
     *
     * Quy tắc:
     * - Luôn tạo hóa đơn FULL tháng đầu (không pro-rated).
     * - Nếu vào giữa tháng (check_in_date.day > 1): tạo thêm hóa đơn quý kế tiếp
     *   (= phí tháng × 3) để sinh viên biết nghĩa vụ thanh toán tiếp theo.
     * - Idempotent: trả về bill cũ nếu đã tồn tại.
     * due_date của tháng đầu = hôm nay + initial_payment_due_days.
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

        $pricePerMonth  = $this->getRoomFeePerMonth();
        $checkIn        = Carbon::parse($occupancy->check_in_date)->startOfDay();
        $month          = $checkIn->month;
        $year           = $checkIn->year;
        $initialDueDate = Carbon::today('Asia/Ho_Chi_Minh')->addDays($dueDays)->toDateString();
        $totalDays      = $checkIn->daysInMonth;

        // Hóa đơn tháng đầu (full tháng, không pro-rated) — idempotent
        $firstBill = RoomFeeBill::where('student_id', $occupancy->student_id)
            ->where('month', $month)
            ->where('year', $year)
            ->first();

        if (! $firstBill) {
            $discount  = $this->discountService->calculate($occupancy, $pricePerMonth);
            $firstBill = RoomFeeBill::create([
                'student_id'       => $occupancy->student_id,
                'occupancy_id'     => $occupancy->id,
                'month'            => $month,
                'year'             => $year,
                'amount'           => $discount['final_amount'],
                'original_amount'  => $discount['original_amount'],
                'discount_percent' => $discount['discount_percent'],
                'discount_amount'  => $discount['discount_amount'],
                'discount_reason'  => $discount['discount_reason'],
                'days_stayed'      => $totalDays,
                'total_days'       => $totalDays,
                'due_date'         => $initialDueDate,
                'status'           => 'unpaid',
            ]);
        }

        // Nếu vào giữa tháng → tạo thêm hóa đơn quý kế tiếp
        if ($checkIn->day > 1) {
            [$nextQMonth, $nextQYear] = $this->nextQuarterFirstMonth($month, $year);

            if (! $this->billExists($occupancy->student_id, $nextQMonth, $nextQYear)) {
                $quarterlyBase = $pricePerMonth * 3;
                $discount      = $this->discountService->calculate($occupancy, $quarterlyBase);

                RoomFeeBill::create([
                    'student_id'       => $occupancy->student_id,
                    'occupancy_id'     => $occupancy->id,
                    'month'            => $nextQMonth,
                    'year'             => $nextQYear,
                    'amount'           => $discount['final_amount'],
                    'original_amount'  => $discount['original_amount'],
                    'discount_percent' => $discount['discount_percent'],
                    'discount_amount'  => $discount['discount_amount'],
                    'discount_reason'  => $discount['discount_reason'],
                    'days_stayed'      => null,
                    'total_days'       => null,
                    'due_date'         => Carbon::create($nextQYear, $nextQMonth, 20)->toDateString(),
                    'status'           => 'unpaid',
                ]);
            }
        }

        return $firstBill;
    }

    /**
     * Trả về [month, year] của tháng đầu tiên trong quý kế tiếp.
     * Ví dụ: tháng 6 (Q2) → trả về [7, $year] (Q3 bắt đầu tháng 7).
     *         tháng 12 (Q4) → trả về [1, $year+1].
     */
    private function nextQuarterFirstMonth(int $month, int $year): array
    {
        $nextQFirstMonth = (int) ceil($month / 3) * 3 + 1;

        if ($nextQFirstMonth > 12) {
            return [1, $year + 1];
        }

        return [$nextQFirstMonth, $year];
    }

    // -------------------------------------------------------------------------
    // Scheduler flow (gọi bởi GenerateMonthlyRoomFeeBillsCommand ngày 01)
    // -------------------------------------------------------------------------

    /**
     * Tạo hóa đơn tháng chỉ định cho tất cả occupancy đang ACTIVE.
     *
     * Quy tắc:
     * - Kiểm tra tồn tại trước khi insert — idempotent.
     *
     * @return array{generated: int, skipped: int}
     */
    public function generateMonthlyBills(int $month, int $year): array
    {
        $generated     = 0;
        $skipped       = 0;
        $pricePerMonth = $this->getRoomFeePerMonth();
        $billingStart  = Carbon::create($year, $month, 1)->startOfDay();

        Occupancy::where('status', 'ACTIVE')
            ->whereNotNull('check_in_date')
            ->each(function (Occupancy $occupancy) use ($month, $year, $billingStart, $pricePerMonth, &$generated, &$skipped) {
                $checkIn = Carbon::parse($occupancy->check_in_date)->startOfDay();

                // Bỏ qua nếu sinh viên chưa đến tháng này
                if ($checkIn->copy()->startOfMonth()->gt($billingStart)) {
                    $skipped++;
                    return;
                }

                // billExists() chống trùng (observer đã tạo hóa đơn tháng check-in prorated)
                if ($this->billExists($occupancy->student_id, $month, $year)) {
                    $skipped++;
                    return;
                }

                $this->createFullMonthBill($occupancy, $month, $year, $pricePerMonth);
                $generated++;
            });

        return ['generated' => $generated, 'skipped' => $skipped];
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
        return RoomFeeBill::where('status', 'unpaid')
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
     * Tạo hóa đơn prorated cho tháng đầu (vào giữa hoặc cuối tháng).
     *
     * Công thức: amount = price / total_days * days_stayed
     * days_stayed = (ngày cuối tháng - ngày check-in + 1)
     * due_date    = truyền vào từ caller (createInitialBill) hoặc ngày 20 tháng sau
     */
    private function createProratedBill(Occupancy $occupancy, Carbon $checkIn, int $pricePerMonth, ?string $dueDate = null): RoomFeeBill
    {
        $month      = $checkIn->month;
        $year       = $checkIn->year;
        $totalDays  = $checkIn->daysInMonth;
        $daysStayed = $totalDays - $checkIn->day + 1;
        $rawAmount  = ($daysStayed === $totalDays)
            ? $pricePerMonth
            : (int) round($pricePerMonth / $totalDays * $daysStayed);

        $dueDate ??= Carbon::create($year, $month, 1)
            ->addMonthNoOverflow()
            ->setDay(20)
            ->toDateString();

        $discount = $this->discountService->calculate($occupancy, $rawAmount);

        return RoomFeeBill::create([
            'student_id'       => $occupancy->student_id,
            'occupancy_id'     => $occupancy->id,
            'month'            => $month,
            'year'             => $year,
            'amount'           => $discount['final_amount'],
            'original_amount'  => $discount['original_amount'],
            'discount_percent' => $discount['discount_percent'],
            'discount_amount'  => $discount['discount_amount'],
            'discount_reason'  => $discount['discount_reason'],
            'days_stayed'      => $daysStayed,
            'total_days'       => $totalDays,
            'due_date'         => $dueDate,
            'status'           => 'unpaid',
        ]);
    }

    /**
     * Tạo hóa đơn đầy đủ cho tháng (days_stayed = total_days).
     *
     * due_date = ngày 20 của tháng đang lập hóa đơn.
     */
    private function createFullMonthBill(Occupancy $occupancy, int $month, int $year, int $pricePerMonth): RoomFeeBill
    {
        $totalDays = Carbon::create($year, $month, 1)->daysInMonth;
        $dueDate   = Carbon::create($year, $month, 20)->toDateString();

        $discount = $this->discountService->calculate($occupancy, $pricePerMonth);

        return RoomFeeBill::create([
            'student_id'       => $occupancy->student_id,
            'occupancy_id'     => $occupancy->id,
            'month'            => $month,
            'year'             => $year,
            'amount'           => $discount['final_amount'],
            'original_amount'  => $discount['original_amount'],
            'discount_percent' => $discount['discount_percent'],
            'discount_amount'  => $discount['discount_amount'],
            'discount_reason'  => $discount['discount_reason'],
            'days_stayed'      => $totalDays,
            'total_days'       => $totalDays,
            'due_date'         => $dueDate,
            'status'           => 'unpaid',
        ]);
    }

    /**
     * Kiểm tra hóa đơn đã tồn tại cho sinh viên trong tháng/năm.
     */
    private function billExists(int $studentId, int $month, int $year): bool
    {
        return RoomFeeBill::where('student_id', $studentId)
            ->where('month', $month)
            ->where('year', $year)
            ->exists();
    }
}
