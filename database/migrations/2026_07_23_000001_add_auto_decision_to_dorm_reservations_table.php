<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dorm_reservations', function (Blueprint $table) {
            if (! Schema::hasColumn('dorm_reservations', 'auto_decision')) {
                $table->enum('auto_decision', ['approve', 'waitlist', 'reject', 'review'])
                    ->nullable()
                    ->after('status');
            }

            if (! Schema::hasColumn('dorm_reservations', 'auto_decision_reason')) {
                $table->text('auto_decision_reason')->nullable()->after('auto_decision');
            }
        });
    }

    public function down(): void
    {
        Schema::table('dorm_reservations', function (Blueprint $table) {
            if (Schema::hasColumn('dorm_reservations', 'auto_decision_reason')) {
                $table->dropColumn('auto_decision_reason');
            }

            if (Schema::hasColumn('dorm_reservations', 'auto_decision')) {
                $table->dropColumn('auto_decision');
            }
        });
    }
};
