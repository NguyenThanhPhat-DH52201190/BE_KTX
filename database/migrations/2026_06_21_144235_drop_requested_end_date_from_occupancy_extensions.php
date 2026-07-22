<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('occupancy_extensions', function (Blueprint $table) {
            $table->dropColumn('requested_end_date');
        });
    }

    public function down(): void
    {
        Schema::table('occupancy_extensions', function (Blueprint $table) {
            $table->date('requested_end_date')->nullable()->after('reason');
        });
    }
};
