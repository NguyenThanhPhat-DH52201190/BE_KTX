<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('room_change_requests')) {
            return;
        }

        Schema::table('room_change_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('room_change_requests', 'change_type')) {
                $table->enum('change_type', ['bed', 'room', 'swap'])->default('bed')->after('occupancy_id');
            }
            if (! Schema::hasColumn('room_change_requests', 'swap_with_occupancy_id')) {
                $table->foreignId('swap_with_occupancy_id')
                    ->nullable()
                    ->after('desired_bed_id')
                    ->constrained('occupancy')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('room_change_requests', 'reason_type')) {
                $table->enum('reason_type', ['health', 'conflict', 'facility', 'other'])
                    ->nullable()
                    ->after('reason');
            }
            if (! Schema::hasColumn('room_change_requests', 'rejection_reason')) {
                $table->string('rejection_reason')->nullable()->after('status');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('room_change_requests')) {
            return;
        }

        Schema::table('room_change_requests', function (Blueprint $table) {
            if (Schema::hasColumn('room_change_requests', 'swap_with_occupancy_id')) {
                $table->dropConstrainedForeignId('swap_with_occupancy_id');
            }
            foreach (['rejection_reason', 'reason_type', 'change_type'] as $column) {
                if (Schema::hasColumn('room_change_requests', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
