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
        if (Schema::hasTable('registrations')) {
            Schema::table('registrations', function (Blueprint $table) {
                if (Schema::hasColumn('registrations', 'assigned_bed_id')) {
                    $table->dropConstrainedForeignId('assigned_bed_id');
                }

                if (Schema::hasColumn('registrations', 'assigned_room_id')) {
                    $table->dropConstrainedForeignId('assigned_room_id');
                }
            });
        }

        if (!Schema::hasTable('occupancy')) {
            Schema::create('occupancy', function (Blueprint $table) {
                $table->id();
                $table->foreignId('registration_id')->constrained('registrations')->cascadeOnDelete();
                $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
                $table->foreignId('room_id')->constrained('rooms')->cascadeOnDelete();
                $table->foreignId('bed_id')->constrained('beds')->cascadeOnDelete();
                $table->date('check_in_date')->nullable();
                $table->date('check_out_date')->nullable();
                $table->string('status');
                $table->unique('student_id');
                $table->unique('bed_id');
            });
        }

        if (Schema::hasTable('violations')) {
            Schema::table('violations', function (Blueprint $table) {
                if (Schema::hasColumn('violations', 'student_id')) {
                    $table->dropConstrainedForeignId('student_id');
                }

                if (Schema::hasColumn('violations', 'room_id')) {
                    $table->dropConstrainedForeignId('room_id');
                }

                if (!Schema::hasColumn('violations', 'occupancy_id')) {
                    $table->foreignId('occupancy_id');
                }

                $table->foreign('occupancy_id')->references('id')->on('occupancy')->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('room_change_log')) {
            Schema::table('room_change_log', function (Blueprint $table) {
                if (Schema::hasColumn('room_change_log', 'student_id')) {
                    $table->dropConstrainedForeignId('student_id');
                }

                if (Schema::hasColumn('room_change_log', 'registration_id')) {
                    $table->dropConstrainedForeignId('registration_id');
                }

                if (Schema::hasColumn('room_change_log', 'transferred_at')) {
                    $table->dropColumn('transferred_at');
                }

                if (!Schema::hasColumn('room_change_log', 'occupancy_id')) {
                    $table->foreignId('occupancy_id')->nullable()->constrained('occupancy')->nullOnDelete();
                }

                if (!Schema::hasColumn('room_change_log', 'transfered_at')) {
                    $table->timestamp('transfered_at')->nullable();
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('room_change_log')) {
            Schema::table('room_change_log', function (Blueprint $table) {
                if (Schema::hasColumn('room_change_log', 'occupancy_id')) {
                    $table->dropConstrainedForeignId('occupancy_id');
                }

                if (Schema::hasColumn('room_change_log', 'transfered_at')) {
                    $table->dropColumn('transfered_at');
                }

                if (!Schema::hasColumn('room_change_log', 'student_id')) {
                    $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
                }

                if (!Schema::hasColumn('room_change_log', 'registration_id')) {
                    $table->foreignId('registration_id')->nullable()->constrained('registrations')->nullOnDelete();
                }

                if (!Schema::hasColumn('room_change_log', 'transferred_at')) {
                    $table->timestamp('transferred_at')->nullable();
                }
            });
        }

        if (Schema::hasTable('occupancy')) {
            Schema::dropIfExists('occupancy');
        }

        if (Schema::hasTable('violations')) {
            Schema::table('violations', function (Blueprint $table) {
                if (Schema::hasColumn('violations', 'occupancy_id')) {
                    $table->dropConstrainedForeignId('occupancy_id');
                }

                if (!Schema::hasColumn('violations', 'student_id')) {
                    $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
                }

                if (!Schema::hasColumn('violations', 'room_id')) {
                    $table->foreignId('room_id')->constrained('rooms')->cascadeOnDelete();
                }
            });
        }

        if (Schema::hasTable('registrations')) {
            Schema::table('registrations', function (Blueprint $table) {
                if (!Schema::hasColumn('registrations', 'assigned_room_id')) {
                    $table->foreignId('assigned_room_id')->nullable()->constrained('rooms')->nullOnDelete();
                }

                if (!Schema::hasColumn('registrations', 'assigned_bed_id')) {
                    $table->foreignId('assigned_bed_id')->nullable()->constrained('beds')->nullOnDelete();
                }
            });
        }
    }
};
