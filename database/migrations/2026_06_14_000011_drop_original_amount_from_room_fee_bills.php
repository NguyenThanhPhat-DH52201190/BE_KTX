<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Xóa cột room_fee_bills.original_amount không còn dùng.
     */
    public function up(): void
    {
        if (Schema::hasTable('room_fee_bills')) {
            Schema::table('room_fee_bills', function (Blueprint $table) {
                if (Schema::hasColumn('room_fee_bills', 'original_amount')) {
                    $table->dropColumn('original_amount');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('room_fee_bills')) {
            Schema::table('room_fee_bills', function (Blueprint $table) {
                if (! Schema::hasColumn('room_fee_bills', 'original_amount')) {
                    $table->decimal('original_amount', 12, 2)->nullable()->after('amount');
                }
            });
        }
    }
};
