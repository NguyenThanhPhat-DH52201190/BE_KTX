<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dorm_reservations', function (Blueprint $table) {
            $table->unsignedSmallInteger('top_priority_tier')->default(99)->after('priority_note');
            $table->unsignedInteger('total_priority_score')->default(0)->after('top_priority_tier');
        });
    }

    public function down(): void
    {
        Schema::table('dorm_reservations', function (Blueprint $table) {
            $table->dropColumn(['top_priority_tier', 'total_priority_score']);
        });
    }
};
