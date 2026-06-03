<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('occupancy') || !Schema::hasColumn('occupancy', 'bed_id')) {
            return;
        }

        DB::statement('ALTER TABLE occupancy MODIFY bed_id BIGINT UNSIGNED NULL');
    }

    public function down(): void
    {
        if (!Schema::hasTable('occupancy') || !Schema::hasColumn('occupancy', 'bed_id')) {
            return;
        }

        DB::statement('ALTER TABLE occupancy MODIFY bed_id BIGINT UNSIGNED NOT NULL');
    }
};
