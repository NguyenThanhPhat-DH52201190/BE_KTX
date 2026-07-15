<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dorm_reservations', function (Blueprint $table) {
            if (! Schema::hasColumn('dorm_reservations', 'cancellation_reason')) {
                $table->text('cancellation_reason')->nullable()->after('rejection_reason');
            }
        });
    }

    public function down(): void
    {
        Schema::table('dorm_reservations', function (Blueprint $table) {
            if (Schema::hasColumn('dorm_reservations', 'cancellation_reason')) {
                $table->dropColumn('cancellation_reason');
            }
        });
    }
};
