<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('registration_periods')) {
            return;
        }

        if (Schema::hasColumn('registration_periods', 'period_type')) {
            return;
        }

        Schema::table('registration_periods', function (Blueprint $table) {
            $table->enum('period_type', ['registration', 'extension'])->default('registration')->after('channel');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('registration_periods')) {
            return;
        }

        Schema::table('registration_periods', function (Blueprint $table) {
            $table->dropColumn('period_type');
        });
    }
};
