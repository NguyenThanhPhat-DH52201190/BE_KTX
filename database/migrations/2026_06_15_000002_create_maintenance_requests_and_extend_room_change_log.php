<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('maintenance_requests')) {
            Schema::create('maintenance_requests', function (Blueprint $table) {
                $table->id();
                $table->enum('type', ['ROOM', 'BED']);
                $table->foreignId('room_id')->nullable()->constrained('rooms')->nullOnDelete();
                $table->foreignId('bed_id')->nullable()->constrained('beds')->nullOnDelete();
                $table->text('reason')->nullable();
                $table->enum('status', ['PENDING', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED'])->default('PENDING');
                $table->timestamp('started_at')->nullable();
                $table->timestamp('expected_end_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('room_change_log')) {
            return;
        }

        Schema::table('room_change_log', function (Blueprint $table) {
            if (! Schema::hasColumn('room_change_log', 'change_type')) {
                $table->enum('change_type', ['PERMANENT', 'TEMPORARY_MAINTENANCE', 'SWAP', 'ADMIN_TRANSFER'])
                    ->default('PERMANENT')
                    ->after('transfer_reason');
            }

            if (! Schema::hasColumn('room_change_log', 'maintenance_request_id')) {
                $table->foreignId('maintenance_request_id')
                    ->nullable()
                    ->after('change_type')
                    ->constrained('maintenance_requests')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('room_change_log', 'status')) {
                $table->enum('status', ['ACTIVE', 'RETURNED', 'CANCELLED'])
                    ->nullable()
                    ->after('maintenance_request_id');
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('room_change_log')) {
            Schema::table('room_change_log', function (Blueprint $table) {
                if (Schema::hasColumn('room_change_log', 'maintenance_request_id')) {
                    $table->dropConstrainedForeignId('maintenance_request_id');
                }

                foreach (['status', 'change_type'] as $column) {
                    if (Schema::hasColumn('room_change_log', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        Schema::dropIfExists('maintenance_requests');
    }
};
