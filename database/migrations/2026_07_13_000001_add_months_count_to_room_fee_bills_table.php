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
            if (! Schema::hasColumn('room_fee_bills', 'months_count')) {
                $table->unsignedTinyInteger('months_count')->default(1)->after('year');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('room_fee_bills')) {
            return;
        }

        Schema::table('room_fee_bills', function (Blueprint $table) {
            if (Schema::hasColumn('room_fee_bills', 'months_count')) {
                $table->dropColumn('months_count');
            }
        });
    }
};
