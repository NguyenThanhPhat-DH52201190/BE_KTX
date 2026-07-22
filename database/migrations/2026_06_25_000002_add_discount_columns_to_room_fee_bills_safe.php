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
            // original_amount bị drop ở migration trước, thêm lại an toàn
            if (! Schema::hasColumn('room_fee_bills', 'original_amount')) {
                $table->decimal('original_amount', 12, 2)->nullable()->after('amount');
            }
            if (! Schema::hasColumn('room_fee_bills', 'discount_amount')) {
                $table->decimal('discount_amount', 12, 2)->default(0)->after('original_amount');
            }
            if (! Schema::hasColumn('room_fee_bills', 'discount_percent')) {
                $table->decimal('discount_percent', 5, 2)->nullable()->after('discount_amount');
            }
            if (! Schema::hasColumn('room_fee_bills', 'discount_reason')) {
                $table->string('discount_reason')->nullable()->after('discount_percent');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('room_fee_bills')) {
            return;
        }

        Schema::table('room_fee_bills', function (Blueprint $table) {
            foreach (['discount_reason', 'discount_percent', 'discount_amount', 'original_amount'] as $column) {
                if (Schema::hasColumn('room_fee_bills', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
