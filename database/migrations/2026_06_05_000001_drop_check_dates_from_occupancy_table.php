<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('occupancy')) {
            return;
        }

        Schema::table('occupancy', function (Blueprint $table) {
            if (Schema::hasColumn('occupancy', 'check_in_date')) {
                $table->dropColumn('check_in_date');
            }

            if (Schema::hasColumn('occupancy', 'check_out_date')) {
                $table->dropColumn('check_out_date');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('occupancy')) {
            return;
        }

        Schema::table('occupancy', function (Blueprint $table) {
            if (!Schema::hasColumn('occupancy', 'check_in_date')) {
                $table->date('check_in_date')->nullable()->after('bed_id');
            }

            if (!Schema::hasColumn('occupancy', 'check_out_date')) {
                $table->date('check_out_date')->nullable()->after('check_in_date');
            }
        });
    }
};
