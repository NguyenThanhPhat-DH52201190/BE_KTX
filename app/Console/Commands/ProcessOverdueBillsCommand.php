<?php

namespace App\Console\Commands;

use App\Models\Blacklist;
use App\Models\ElectricityBill;
use App\Models\Notification;
use App\Models\Occupancy;
use App\Models\RoomFeeBill;
use App\Models\Student;
use App\Models\StudentPaymentPlan;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ProcessOverdueBillsCommand extends Command
{
    protected $signature = 'bills:process-overdue
                            {--dry-run : Chạy thử, không ghi database hay gửi email}';

    protected $description = 'Xử lý hóa đơn quá hạn: nhắc nợ (LEVEL 1-3), buộc thôi ở, thêm blacklist';

    private bool $isDry;
    private int $remindersSent  = 0;
    private int $evictions      = 0;
    private int $blacklisted    = 0;

    private int $level1Days;
    private int $level2Days;
    private int $level3Days;
    private int $evictionDays;

    /** @var \Illuminate\Support\Collection<int, int> Sinh viên đang có chế độ "đóng theo tháng" active — bỏ qua buộc thôi ở/blacklist */
    private \Illuminate\Support\Collection $installmentStudentIds;

    public function handle(): int
    {
        $this->isDry = (bool) $this->option('dry-run');

        $this->loadOverdueSettings();

        $this->installmentStudentIds = StudentPaymentPlan::query()
            ->where('type', 'installment')
            ->where('is_active', true)
            ->pluck('student_id');

        $this->info(sprintf(
            '[bills:process-overdue] %s',
            $this->isDry ? 'DRY RUN — không ghi dữ liệu.' : 'Bắt đầu xử lý hóa đơn quá hạn...'
        ));

        // Lấy tất cả occupancy ACTIVE đang có ít nhất 1 bill overdue
        Occupancy::where('status', 'ACTIVE')
            ->whereHas('roomFeeBills', fn ($q) => $q->where('status', 'overdue'))
            ->with([
                'student',
                // Sắp xếp bills theo due_date ASC → bill[0] là bill cũ nhất (nặng nhất)
                'roomFeeBills' => fn ($q) => $q->where('status', 'overdue')->orderBy('due_date'),
            ])
            ->each(fn (Occupancy $occ) => $this->processOccupancy($occ));

        $this->info("Nhắc nợ gửi đi  : {$this->remindersSent}");
        $this->info("Buộc thôi ở     : {$this->evictions}");
        $this->info("Thêm blacklist   : {$this->blacklisted}");

        return self::SUCCESS;
    }

    // -------------------------------------------------------------------------
    // Per-occupancy logic
    // -------------------------------------------------------------------------

    private function processOccupancy(Occupancy $occupancy): void
    {
        $student = $occupancy->student;
        if (! $student) {
            return;
        }

        /** @var Collection<RoomFeeBill> $overdueBills */
        $overdueBills = $occupancy->roomFeeBills;

        // Gộp thêm hóa đơn điện quá hạn của cùng sinh viên
        /** @var Collection<ElectricityBill> $overdueElecBills */
        $overdueElecBills = ElectricityBill::where('student_id', $student->id)
            ->where('status', 'overdue')
            ->orderBy('due_date')
            ->get();

        // Dùng due_date của bill cũ nhất để tính mức độ nặng nhất
        /** @var RoomFeeBill $oldestBill */
        $oldestBill  = $overdueBills->first();
        $daysOverdue = (int) Carbon::parse($oldestBill->due_date)->diffInDays(Carbon::today());

        // ---- Buộc thôi ở (evictionDays + đã có LEVEL_3) --------------------
        // Bỏ qua nếu sinh viên đang được duyệt "đóng theo tháng" — vẫn nhắc nợ bình thường bên dưới.
        if (
            ! $this->installmentStudentIds->contains($student->id)
            && $daysOverdue >= $this->evictionDays
            && $this->hasReminder($student->id, 3)
        ) {
            $this->forceEviction($occupancy, $student, $overdueBills, $overdueElecBills);
            return;
        }

        // ---- Nhắc nợ theo mốc (cao nhất chưa gửi) --------------------------
        $newLevel = $this->resolveNewReminderLevel($student->id, $daysOverdue);

        if ($newLevel !== null) {
            $this->sendReminder($occupancy, $student, $overdueBills, $overdueElecBills, $newLevel, $daysOverdue);
        }
    }

    /**
     * Xác định mức nhắc nợ mới nhất cần gửi cho sinh viên.
     * Mỗi mức chỉ gửi một lần (kiểm tra payment_reminders).
     */
    private function resolveNewReminderLevel(int $studentId, int $daysOverdue): ?int
    {
        if ($daysOverdue >= $this->level3Days && ! $this->hasReminder($studentId, 3)) {
            return 3;
        }
        if ($daysOverdue >= $this->level2Days && ! $this->hasReminder($studentId, 2)) {
            return 2;
        }
        if ($daysOverdue >= $this->level1Days && ! $this->hasReminder($studentId, 1)) {
            return 1;
        }

        return null;
    }

    /**
     * Kiểm tra sinh viên đã nhận reminder ở mức này chưa
     * (dựa trên bất kỳ bill nào của sinh viên đó).
     */
    private function hasReminder(int $studentId, int $level): bool
    {
        return DB::table('payment_reminders')
            ->where('bill_type', 'room_fee')
            ->where('student_id', $studentId)
            ->where('reminder_level', $level)
            ->exists();
    }

    // -------------------------------------------------------------------------
    // Send reminder
    // -------------------------------------------------------------------------

    private function sendReminder(
        Occupancy $occupancy,
        Student $student,
        Collection $overdueBills,
        Collection $overdueElecBills,
        int $level,
        int $daysOverdue
    ): void {
        $totalOwed = $overdueBills->sum('amount') + $overdueElecBills->sum('amount');
        $sentAt    = Carbon::now();

        if (! $this->isDry) {
            // 1. Tạo payment_reminder cho từng room fee bill overdue
            foreach ($overdueBills as $bill) {
                DB::table('payment_reminders')->insert([
                    'bill_type'      => 'room_fee',
                    'bill_id'        => $bill->id,
                    'student_id'     => $student->id,
                    'reminder_level' => $level,
                    'due_amount'     => $bill->amount,
                    'sent_at'        => $sentAt,
                    'note'           => "Auto: quá hạn {$daysOverdue} ngày",
                    'created_at'     => $sentAt,
                    'updated_at'     => $sentAt,
                ]);
            }

            // 1b. Tạo payment_reminder cho từng electricity bill overdue
            foreach ($overdueElecBills as $bill) {
                DB::table('payment_reminders')->insert([
                    'bill_type'      => 'electricity',
                    'bill_id'        => $bill->id,
                    'student_id'     => $student->id,
                    'reminder_level' => $level,
                    'due_amount'     => $bill->amount,
                    'sent_at'        => $sentAt,
                    'note'           => "Auto: quá hạn {$daysOverdue} ngày",
                    'created_at'     => $sentAt,
                    'updated_at'     => $sentAt,
                ]);
            }

            // 2. Tạo notification in-app
            $this->createNotification($student, $level, $daysOverdue, $totalOwed);

            // 3. Gửi email
            $this->sendEmail($student, $level, $daysOverdue, $totalOwed, $overdueBills, $overdueElecBills);
        }

        $levelLabel = ['', 'LEVEL_1', 'LEVEL_2', 'LEVEL_3'][$level];
        $this->line("  [{$levelLabel}] {$student->full_name} ({$student->student_code}) — quá hạn {$daysOverdue} ngày, nợ " . number_format($totalOwed) . "đ");
        $this->remindersSent++;
    }

    private function createNotification(Student $student, int $level, int $daysOverdue, int $totalOwed): void
    {
        [$title, $content] = $this->notificationTexts($level, $daysOverdue, $totalOwed);

        $notification = Notification::create([
            'student_id' => $student->id,
            'title'      => $title,
            'content'    => $content,
            'type'       => 'payment_reminder',
            'target_type' => 'individual',
            'send_email' => true,
        ]);

        DB::table('notification_recipient')->insert([
            'notification_id' => $notification->id,
            'student_id'      => $student->id,
            'is_read'         => false,
            'read_at'         => null,
        ]);
    }

    private function sendEmail(Student $student, int $level, int $daysOverdue, int $totalOwed, Collection $bills, Collection $elecBills): void
    {
        if (! $student->email) {
            return;
        }

        [$subject, $body] = $this->emailTexts($level, $daysOverdue, $totalOwed, $student, $bills, $elecBills);

        try {
            Mail::send([], [], function ($message) use ($student, $subject, $body) {
                $message->to($student->email, $student->full_name)
                    ->subject($subject)
                    ->html($body);
            });
        } catch (\Throwable $e) {
            Log::error("[ProcessOverdue] Gửi email thất bại cho student #{$student->id}: " . $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Force eviction
    // -------------------------------------------------------------------------

    private function forceEviction(Occupancy $occupancy, Student $student, Collection $overdueBills, Collection $overdueElecBills): void
    {
        $totalOwed = $overdueBills->sum('amount') + $overdueElecBills->sum('amount');

        if (! $this->isDry) {
            DB::transaction(function () use ($occupancy, $student, $totalOwed, $overdueBills) {
                // 1. Kết thúc lưu trú (reason dùng để phân biệt với checkout tự nguyện)
                $occupancy->update([
                    'status'         => 'COMPLETED',
                    'check_out_date' => Carbon::today()->toDateString(),
                    'reason'         => 'FORCE_EVICTED',
                ]);

                // 2. Thêm blacklist (chống trùng)
                $alreadyBlacklisted = Blacklist::where('student_id', $student->id)->exists();
                if (! $alreadyBlacklisted) {
                    Blacklist::create([
                        'student_id' => $student->id,
                        'reason'     => 'Nợ quá hạn kéo dài',
                        'source'     => 'overdue_payment',
                        'created_by' => null, // hệ thống tự động
                    ]);
                    $this->blacklisted++;
                }

                // 3. Tạo notification buộc thôi ở
                $notification = Notification::create([
                    'student_id'  => $student->id,
                    'title'       => 'Thông báo: Bạn đã bị buộc thôi ở',
                    'content'     => "Do không thanh toán tiền phòng trong thời gian quy định, bạn đã bị buộc thôi ở. "
                        . "Bạn vẫn có thể đăng nhập hệ thống và thanh toán " . number_format($totalOwed) . "đ còn nợ để tất toán công nợ.",
                    'type'        => 'eviction',
                    'target_type' => 'individual',
                    'send_email'  => true,
                ]);

                DB::table('notification_recipient')->insert([
                    'notification_id' => $notification->id,
                    'student_id'      => $student->id,
                    'is_read'         => false,
                    'read_at'         => null,
                ]);

                // 4. Gửi email thôi ở
                $this->sendEvictionEmail($student, $totalOwed, $overdueBills, $overdueElecBills);
            });
        }

        $this->warn("  [EVICTION] {$student->full_name} ({$student->student_code}) — nợ " . number_format($totalOwed) . "đ → COMPLETED + blacklist");
        $this->evictions++;
    }

    private function sendEvictionEmail(Student $student, int $totalOwed, Collection $bills, Collection $elecBills): void
    {
        if (! $student->email) {
            return;
        }

        $billRows = $bills->map(fn ($b) =>
            "<tr><td style='padding:4px 8px'>Tiền phòng tháng {$b->month}/{$b->year}</td>"
            . "<td style='padding:4px 8px;text-align:right'>" . number_format($b->amount) . "đ</td></tr>"
        )->implode('');

        $billRows .= $elecBills->map(fn ($b) =>
            "<tr><td style='padding:4px 8px'>Tiền điện tháng " . date('m/Y', strtotime($b->month_year . '-01')) . "</td>"
            . "<td style='padding:4px 8px;text-align:right'>" . number_format($b->amount) . "đ</td></tr>"
        )->implode('');

        $body = <<<HTML
<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto">
  <h2 style="color:#dc2626">Thông báo buộc thôi ở — KTX Trường ĐH Công nghệ Sài Gòn</h2>
  <p>Kính gửi <strong>{$student->full_name}</strong>,</p>
  <p>Do không thanh toán tiền phòng trong thời gian quy định, tài khoản lưu trú của bạn đã bị chấm dứt.</p>
  <h3>Chi tiết công nợ còn lại:</h3>
  <table border="1" cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%">
    <thead><tr style="background:#f3f4f6">
      <th style="padding:6px 8px;text-align:left">Tháng</th>
      <th style="padding:6px 8px;text-align:right">Số tiền</th>
    </tr></thead>
    <tbody>{$billRows}</tbody>
    <tfoot><tr style="background:#fef2f2;font-weight:bold">
      <td style="padding:6px 8px">Tổng cộng</td>
      <td style="padding:6px 8px;text-align:right">{$totalOwed}đ</td>
    </tfoot>
  </table>
  <p style="margin-top:16px">Bạn <strong>vẫn có thể đăng nhập</strong> hệ thống tại địa chỉ web KTX để xem và thanh toán các khoản nợ còn lại.</p>
  <p style="color:#6b7280;font-size:12px">Email này được gửi tự động bởi hệ thống quản lý KTX.</p>
</div>
HTML;

        try {
            Mail::send([], [], function ($message) use ($student, $body) {
                $message->to($student->email, $student->full_name)
                    ->subject('KTX STU — Thông báo buộc thôi ở')
                    ->html($body);
            });
        } catch (\Throwable $e) {
            Log::error("[ProcessOverdue] Gửi email eviction thất bại cho student #{$student->id}: " . $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Content helpers
    // -------------------------------------------------------------------------

    /** @return array{0: string, 1: string} [title, content] */
    private function notificationTexts(int $level, int $daysOverdue, int $totalOwed): array
    {
        $amount = number_format($totalOwed) . 'đ';

        return match ($level) {
            1 => [
                'Nhắc nhở: Hóa đơn KTX quá hạn',
                "Bạn có hóa đơn KTX (tiền phòng/tiền điện) đã quá hạn {$daysOverdue} ngày (tổng nợ: {$amount}). "
                    . 'Vui lòng thanh toán sớm để tránh bị xử lý ở mức cao hơn.',
            ],
            2 => [
                'Cảnh báo: Nợ hóa đơn KTX chưa thanh toán',
                "Bạn có hóa đơn KTX (tiền phòng/tiền điện) đã quá hạn {$daysOverdue} ngày (tổng nợ: {$amount}). "
                    . 'Đây là lần nhắc thứ hai. Nếu không thanh toán, bạn sẽ nhận cảnh báo cuối cùng.',
            ],
            3 => [
                'Cảnh báo cuối: Nguy cơ bị buộc thôi ở sau 7 ngày',
                "Bạn có hóa đơn KTX (tiền phòng/tiền điện) đã quá hạn {$daysOverdue} ngày (tổng nợ: {$amount}). "
                    . 'Đây là cảnh báo CUỐI CÙNG. Bạn còn 7 ngày để thanh toán trước khi bị buộc thôi ở và đưa vào danh sách đen.',
            ],
            default => ['Nhắc nhở thanh toán', ''],
        };
    }

    /** @return array{0: string, 1: string} [subject, htmlBody] */
    private function emailTexts(int $level, int $daysOverdue, int $totalOwed, Student $student, Collection $bills, Collection $elecBills): array
    {
        $amount = number_format($totalOwed) . 'đ';

        $levelLabel = ['', 'Nhắc nhở (Lần 1)', 'Cảnh báo (Lần 2)', 'Cảnh báo cuối (Lần 3)'][$level];
        $subjects   = [
            '',
            'KTX STU — Nhắc nhở thanh toán hóa đơn KTX',
            'KTX STU — Cảnh báo nợ hóa đơn KTX lần 2',
            'KTX STU — [KHẨN] Cảnh báo cuối, nguy cơ bị buộc thôi ở',
        ];

        $alertColor = ['', '#d97706', '#dc2626', '#7f1d1d'][$level];
        $bgColor    = ['', '#fffbeb', '#fef2f2', '#fef2f2'][$level];

        $billRows = $bills->map(fn ($b) =>
            "<tr><td style='padding:4px 8px'>Tiền phòng tháng {$b->month}/{$b->year}</td>"
            . "<td style='padding:4px 8px;text-align:right'>" . number_format($b->amount) . "đ</td>"
            . "<td style='padding:4px 8px'>Hạn: " . ($b->due_date instanceof \Carbon\Carbon ? $b->due_date->format('d/m/Y') : $b->due_date) . "</td></tr>"
        )->implode('');

        $billRows .= $elecBills->map(fn ($b) =>
            "<tr><td style='padding:4px 8px'>Tiền điện tháng " . date('m/Y', strtotime($b->month_year . '-01')) . "</td>"
            . "<td style='padding:4px 8px;text-align:right'>" . number_format($b->amount) . "đ</td>"
            . "<td style='padding:4px 8px'>Hạn: " . ($b->due_date instanceof \Carbon\Carbon ? $b->due_date->format('d/m/Y') : $b->due_date) . "</td></tr>"
        )->implode('');

        $extraWarning = $level === 3
            ? "<p style='color:#7f1d1d;font-weight:bold;border:1px solid #dc2626;padding:12px;border-radius:4px'>"
                . "⚠️ Bạn còn <strong>7 ngày</strong> kể từ ngày nhận email này để thanh toán. "
                . "Nếu không thanh toán, tài khoản lưu trú sẽ bị chấm dứt và bạn sẽ bị đưa vào danh sách đen.</p>"
            : '';

        $body = <<<HTML
<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:{$bgColor};padding:24px;border-radius:8px">
  <h2 style="color:{$alertColor}">{$levelLabel} — Tiền phòng KTX</h2>
  <p>Kính gửi <strong>{$student->full_name}</strong> ({$student->student_code}),</p>
  <p>Hóa đơn tiền phòng của bạn đã <strong>quá hạn {$daysOverdue} ngày</strong>.</p>
  <h3>Danh sách hóa đơn chưa thanh toán:</h3>
  <table border="1" cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%">
    <thead><tr style="background:#e5e7eb">
      <th style="padding:6px 8px;text-align:left">Tháng</th>
      <th style="padding:6px 8px;text-align:right">Số tiền</th>
      <th style="padding:6px 8px;text-align:left">Hạn thanh toán</th>
    </tr></thead>
    <tbody>{$billRows}</tbody>
    <tfoot><tr style="font-weight:bold;background:#e5e7eb">
      <td style="padding:6px 8px">Tổng nợ</td>
      <td style="padding:6px 8px;text-align:right">{$amount}</td>
      <td></td>
    </tr></tfoot>
  </table>
  {$extraWarning}
  <p>Vui lòng đăng nhập hệ thống KTX để thanh toán.</p>
  <p style="color:#6b7280;font-size:12px">Email này được gửi tự động. Vui lòng không trả lời email này.</p>
</div>
HTML;

        return [$subjects[$level], $body];
    }

    private function loadOverdueSettings(): void
    {
        $settings = DB::table('settings')
            ->whereIn('key', [
                'overdue_level1_days',
                'overdue_level2_days',
                'overdue_level3_days',
                'overdue_eviction_days',
            ])
            ->pluck('value', 'key');

        $this->level1Days   = (int) ($settings['overdue_level1_days']   ?? 7);
        $this->level2Days   = (int) ($settings['overdue_level2_days']   ?? 30);
        $this->level3Days   = (int) ($settings['overdue_level3_days']   ?? 90);
        $this->evictionDays = (int) ($settings['overdue_eviction_days'] ?? 97);
    }
}
