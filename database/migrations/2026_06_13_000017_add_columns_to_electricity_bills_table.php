<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('electricity_bills')) {
            return;
        }

        Schema::table('electricity_bills', function (Blueprint $table) {
            if (! Schema::hasColumn('electricity_bills', 'electricity_record_id')) {
                $table->foreignId('electricity_record_id')
                    ->nullable()
                    ->after('registration_id')
                    ->constrained('electricity_records')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('electricity_bills', 'occupancy_id')) {
                $table->foreignId('occupancy_id')
                    ->nullable()
                    ->after('electricity_record_id')
                    ->constrained('occupancy')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('electricity_bills', 'days_stayed')) {
                $table->integer('days_stayed')->nullable()->after('amount');
            }
            if (! Schema::hasColumn('electricity_bills', 'total_days')) {
                $table->integer('total_days')->nullable()->after('days_stayed');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('electricity_bills')) {
            return;
        }

        Schema::table('electricity_bills', function (Blueprint $table) {
            foreach (['days_stayed', 'total_days'] as $column) {
                if (Schema::hasColumn('electricity_bills', $column)) {
                    $table->dropColumn($column);
                }
            }
            if (Schema::hasColumn('electricity_bills', 'occupancy_id')) {
                $table->dropConstrainedForeignId('occupancy_id');
            }
            if (Schema::hasColumn('electricity_bills', 'electricity_record_id')) {
                $table->dropConstrainedForeignId('electricity_record_id');
            }
        });
    }
};
