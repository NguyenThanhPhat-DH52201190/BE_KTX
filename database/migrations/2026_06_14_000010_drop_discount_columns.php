<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Xóa các cột giảm giá không còn dùng:
     *   - room_fee_bills.discount_amount
     *   - room_fee_bills.discount_percent
     *   - priority_criteria.discount_percent
     */
    public function up(): void
    {
        if (Schema::hasTable('room_fee_bills')) {
            Schema::table('room_fee_bills', function (Blueprint $table) {
                foreach (['discount_amount', 'discount_percent'] as $column) {
                    if (Schema::hasColumn('room_fee_bills', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('priority_criteria')) {
            Schema::table('priority_criteria', function (Blueprint $table) {
                if (Schema::hasColumn('priority_criteria', 'discount_percent')) {
                    $table->dropColumn('discount_percent');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('room_fee_bills')) {
            Schema::table('room_fee_bills', function (Blueprint $table) {
                if (! Schema::hasColumn('room_fee_bills', 'discount_amount')) {
                    $table->decimal('discount_amount', 12, 2)->default(0)->after('amount');
                }
                if (! Schema::hasColumn('room_fee_bills', 'discount_percent')) {
                    $table->decimal('discount_percent', 5, 2)->nullable()->after('original_amount');
                }
            });
        }

        if (Schema::hasTable('priority_criteria')) {
            Schema::table('priority_criteria', function (Blueprint $table) {
                if (! Schema::hasColumn('priority_criteria', 'discount_percent')) {
                    $table->decimal('discount_percent', 5, 2)->default(0)->after('priority_score');
                }
            });
        }
    }
};
