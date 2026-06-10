<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('room_fee_bills')
            ->whereNull('month')
            ->update(['month' => 1]);

        DB::statement('ALTER TABLE room_fee_bills MODIFY month INT NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE room_fee_bills MODIFY month INT NULL');
    }
};
