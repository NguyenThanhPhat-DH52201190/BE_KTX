<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('occupancy_extensions')) {
            return;
        }

        Schema::create('occupancy_extensions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('occupancy_id')->constrained('occupancy')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('registration_period_id')->constrained('registration_periods')->cascadeOnDelete();
            $table->date('requested_end_date');
            $table->text('reason');
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->text('admin_note')->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['occupancy_id', 'registration_period_id'], 'occ_ext_occupancy_period_unique');
            $table->index('student_id', 'occ_ext_student_idx');
            $table->index(['status', 'registration_period_id'], 'occ_ext_status_period_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('occupancy_extensions');
    }
};
