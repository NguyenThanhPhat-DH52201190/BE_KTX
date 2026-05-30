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
        if (Schema::hasTable('floors') && Schema::hasColumn('floors', 'total_rooms')) {
            Schema::table('floors', function (Blueprint $table) {
                $table->dropColumn('total_rooms');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('floors') && !Schema::hasColumn('floors', 'total_rooms')) {
            Schema::table('floors', function (Blueprint $table) {
                $table->integer('total_rooms')->default(0)->after('floor_number');
            });
        }
    }
};