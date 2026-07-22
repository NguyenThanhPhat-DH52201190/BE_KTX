<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE registration_periods
            MODIFY COLUMN initial_payment_due_days INT UNSIGNED NOT NULL
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE registration_periods
            MODIFY COLUMN initial_payment_due_days INT UNSIGNED NOT NULL DEFAULT 30
        ");
    }
};
