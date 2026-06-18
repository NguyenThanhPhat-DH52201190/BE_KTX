<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $rows = [
            [
                'key'         => 'overdue_level1_days',
                'value'       => '7',
                'description' => 'Số ngày quá hạn để gửi nhắc nợ lần 1 (LEVEL 1)',
            ],
            [
                'key'         => 'overdue_level2_days',
                'value'       => '30',
                'description' => 'Số ngày quá hạn để gửi cảnh báo lần 2 (LEVEL 2)',
            ],
            [
                'key'         => 'overdue_level3_days',
                'value'       => '90',
                'description' => 'Số ngày quá hạn để gửi cảnh báo cuối (LEVEL 3)',
            ],
            [
                'key'         => 'overdue_eviction_days',
                'value'       => '97',
                'description' => 'Số ngày quá hạn để buộc thôi ở (sau LEVEL 3 + 7 ngày)',
            ],
        ];

        foreach ($rows as $row) {
            DB::table('settings')->insertOrIgnore($row);
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', [
            'overdue_level1_days',
            'overdue_level2_days',
            'overdue_level3_days',
            'overdue_eviction_days',
        ])->delete();
    }
};
