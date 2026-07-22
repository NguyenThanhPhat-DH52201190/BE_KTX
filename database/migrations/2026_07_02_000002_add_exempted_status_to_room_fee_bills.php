<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('room_fee_bills')) {
            return;
        }

        DB::statement("ALTER TABLE room_fee_bills MODIFY COLUMN status ENUM('unpaid','paid','overdue','exempted') NOT NULL DEFAULT 'unpaid'");
    }

    public function down(): void
    {
        if (! Schema::hasTable('room_fee_bills')) {
            return;
        }

        DB::table('room_fee_bills')->where('status', 'exempted')->update(['status' => 'unpaid']);

        DB::statement("ALTER TABLE room_fee_bills MODIFY COLUMN status ENUM('unpaid','paid','overdue') NOT NULL DEFAULT 'unpaid'");
    }
};
