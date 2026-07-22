<?php

namespace App\Console\Commands;

use App\Helpers\ProvinceHelper;
use App\Models\Student;
use Illuminate\Console\Command;

class MapStudentsProvince extends Command
{
    protected $signature = 'provinces:map-students {--dry-run : Chỉ in báo cáo, không ghi DB}';

    protected $description = 'Ánh xạ permanent_address của sinh viên sang province_code mới (34 tỉnh sau sáp nhập 2025)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $students = Student::query()
            ->whereNull('province_code')
            ->whereNotNull('permanent_address')
            ->get(['id', 'permanent_address', 'province_code']);

        $total = $students->count();
        $mapped = 0;
        $stillNull = 0;

        foreach ($students as $student) {
            $code = $this->resolveCode($student->permanent_address);

            if ($code === null) {
                $stillNull++;
                continue;
            }

            if (! $dryRun) {
                $student->province_code = $code;
                $student->save();
            }

            $mapped++;
        }

        $this->newLine();
        $this->info('=== BÁO CÁO ÁNH XẠ TỈNH/THÀNH ===');
        $this->line('Chế độ:           ' . ($dryRun ? 'DRY-RUN (không ghi DB)' : 'GHI DB'));
        $this->line('Tổng cần xử lý:   ' . $total . ' (province_code = NULL, có permanent_address)');
        $this->line('Ánh xạ được:      ' . $mapped);
        $this->line('Còn NULL:         ' . $stillNull . ' (chờ admin điền tay)');
        $this->newLine();

        return self::SUCCESS;
    }

    private function resolveCode(?string $address): ?string
    {
        return ProvinceHelper::resolveCode($address);
    }
}
