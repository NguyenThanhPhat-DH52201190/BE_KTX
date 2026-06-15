<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('room_fee_bills')) {
            return;
        }

        Schema::table('room_fee_bills', function (Blueprint $table) {
            if (!Schema::hasColumn('room_fee_bills', 'discount_amount')) {
                $table->decimal('discount_amount', 12, 2)->default(0)->after('amount');
            }

            if (!Schema::hasColumn('room_fee_bills', 'priority_criteria_id')) {
                $table->foreignId('priority_criteria_id')
                    ->nullable()
                    ->constrained('priority_criteria')
                    ->nullOnDelete()
                    ->after('discount_amount');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('room_fee_bills')) {
            return;
        }

        Schema::table('room_fee_bills', function (Blueprint $table) {
            if (Schema::hasColumn('room_fee_bills', 'priority_criteria_id')) {
                $table->dropConstrainedForeignId('priority_criteria_id');
            }

            if (Schema::hasColumn('room_fee_bills', 'discount_amount')) {
                $table->dropColumn('discount_amount');
            }
        });
    }
};
