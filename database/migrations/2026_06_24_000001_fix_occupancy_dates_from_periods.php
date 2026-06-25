<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            UPDATE occupancy o
            JOIN registrations r  ON r.id  = o.registration_id
            JOIN registration_periods rp ON rp.id = r.registration_period_id
            SET
                o.check_in_date  = COALESCE(o.check_in_date,  rp.stay_start_date),
                o.check_out_date = COALESCE(o.check_out_date, rp.stay_end_date)
            WHERE rp.stay_end_date IS NOT NULL
              AND (o.check_in_date IS NULL OR o.check_out_date IS NULL)
        ");
    }

    public function down(): void
    {
        // không thể rollback data update
    }
};
