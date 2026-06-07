<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('violation_types') || !Schema::hasColumn('violation_types', 'status')) {
            return;
        }

        Schema::table('violation_types', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('violation_types') || Schema::hasColumn('violation_types', 'status')) {
            return;
        }

        Schema::table('violation_types', function (Blueprint $table) {
            $table->string('status')->default('active');
        });
    }
};
