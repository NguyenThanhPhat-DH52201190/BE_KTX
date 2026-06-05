<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('occupancy') || Schema::hasColumn('occupancy', 'reason')) {
            return;
        }

        Schema::table('occupancy', function (Blueprint $table) {
            $table->text('reason')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('occupancy') || !Schema::hasColumn('occupancy', 'reason')) {
            return;
        }

        Schema::table('occupancy', function (Blueprint $table) {
            $table->dropColumn('reason');
        });
    }
};
