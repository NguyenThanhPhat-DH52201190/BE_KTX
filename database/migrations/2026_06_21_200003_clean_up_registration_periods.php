<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Remove extension period rows (now migrated to occupancy_periods)
        DB::table('registration_periods')->where('period_type', 'extension')->delete();

        // Drop period_type column — registration_periods is now registration-only
        Schema::table('registration_periods', function (Blueprint $table) {
            $table->dropColumn('period_type');
        });
    }

    public function down(): void
    {
        Schema::table('registration_periods', function (Blueprint $table) {
            $table->enum('period_type', ['registration', 'extension'])
                  ->default('registration')
                  ->after('channel');
        });
    }
};
