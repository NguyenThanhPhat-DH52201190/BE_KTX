<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('room_fee_bills')) {
            return;
        }

        Schema::table('room_fee_bills', function (Blueprint $table) {
            if (! Schema::hasColumn('room_fee_bills', 'original_amount')) {
                $table->decimal('original_amount', 12, 2)->nullable()->after('amount');
            }
            if (! Schema::hasColumn('room_fee_bills', 'discount_percent')) {
                $table->decimal('discount_percent', 5, 2)->nullable()->after('discount_amount');
            }
            if (! Schema::hasColumn('room_fee_bills', 'days_stayed')) {
                $table->integer('days_stayed')->nullable()->after('discount_percent');
            }
            if (! Schema::hasColumn('room_fee_bills', 'total_days')) {
                $table->integer('total_days')->nullable()->after('days_stayed');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('room_fee_bills')) {
            return;
        }

        Schema::table('room_fee_bills', function (Blueprint $table) {
            foreach (['total_days', 'days_stayed', 'discount_percent', 'original_amount'] as $column) {
                if (Schema::hasColumn('room_fee_bills', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
