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
            if (! Schema::hasColumn('room_fee_bills', 'admin_note')) {
                $table->string('admin_note')->nullable()->after('discount_reason');
            }
            if (! Schema::hasColumn('room_fee_bills', 'exempted_by')) {
                $table->string('exempted_by')->nullable()->after('admin_note');
            }
            if (! Schema::hasColumn('room_fee_bills', 'exempted_at')) {
                $table->timestamp('exempted_at')->nullable()->after('exempted_by');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('room_fee_bills')) {
            return;
        }

        Schema::table('room_fee_bills', function (Blueprint $table) {
            foreach (['exempted_at', 'exempted_by', 'admin_note'] as $column) {
                if (Schema::hasColumn('room_fee_bills', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
