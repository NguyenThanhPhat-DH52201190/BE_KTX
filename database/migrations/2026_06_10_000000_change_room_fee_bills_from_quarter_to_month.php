<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_fee_bills', function (Blueprint $table) {
            if (!Schema::hasColumn('room_fee_bills', 'month')) {
                $table->integer('month')->nullable()->after('registration_id');
            }
        });

        if (Schema::hasColumn('room_fee_bills', 'quarter')) {
            DB::table('room_fee_bills')
                ->whereNull('month')
                ->update([
                    'month' => DB::raw('CASE quarter WHEN 1 THEN 1 WHEN 2 THEN 4 WHEN 3 THEN 7 WHEN 4 THEN 10 ELSE 1 END'),
                ]);

            Schema::table('room_fee_bills', function (Blueprint $table) {
                $table->dropColumn('quarter');
            });
        }
    }

    public function down(): void
    {
        Schema::table('room_fee_bills', function (Blueprint $table) {
            if (!Schema::hasColumn('room_fee_bills', 'quarter')) {
                $table->integer('quarter')->nullable()->after('registration_id');
            }
        });

        if (Schema::hasColumn('room_fee_bills', 'month')) {
            DB::table('room_fee_bills')
                ->whereNull('quarter')
                ->update([
                    'quarter' => DB::raw('CEIL(month / 3)'),
                ]);

            Schema::table('room_fee_bills', function (Blueprint $table) {
                $table->dropColumn('month');
            });
        }
    }
};
