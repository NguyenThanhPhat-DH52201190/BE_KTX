<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('room_change_log')) {
            return;
        }

        Schema::table('room_change_log', function (Blueprint $table) {
            if (! Schema::hasColumn('room_change_log', 'completed_at')) {
                $table->timestamp('completed_at')->nullable()->after('transferred_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('room_change_log')) {
            return;
        }

        Schema::table('room_change_log', function (Blueprint $table) {
            if (Schema::hasColumn('room_change_log', 'completed_at')) {
                $table->dropColumn('completed_at');
            }
        });
    }
};
