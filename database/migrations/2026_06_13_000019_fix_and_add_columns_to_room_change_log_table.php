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

        // Fix the misspelled column transfered_at -> transferred_at.
        if (Schema::hasColumn('room_change_log', 'transfered_at')
            && ! Schema::hasColumn('room_change_log', 'transferred_at')) {
            Schema::table('room_change_log', function (Blueprint $table) {
                $table->renameColumn('transfered_at', 'transferred_at');
            });
        }

        Schema::table('room_change_log', function (Blueprint $table) {
            if (! Schema::hasColumn('room_change_log', 'change_source')) {
                $table->enum('change_source', ['student_request', 'admin'])->default('admin')->after('transfer_reason');
            }
            if (! Schema::hasColumn('room_change_log', 'is_temporary')) {
                $table->boolean('is_temporary')->default(false)->after('change_source');
            }
            if (! Schema::hasColumn('room_change_log', 'expected_return_date')) {
                $table->date('expected_return_date')->nullable()->after('is_temporary');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('room_change_log')) {
            return;
        }

        Schema::table('room_change_log', function (Blueprint $table) {
            foreach (['expected_return_date', 'is_temporary', 'change_source'] as $column) {
                if (Schema::hasColumn('room_change_log', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        if (Schema::hasColumn('room_change_log', 'transferred_at')
            && ! Schema::hasColumn('room_change_log', 'transfered_at')) {
            Schema::table('room_change_log', function (Blueprint $table) {
                $table->renameColumn('transferred_at', 'transfered_at');
            });
        }
    }
};
