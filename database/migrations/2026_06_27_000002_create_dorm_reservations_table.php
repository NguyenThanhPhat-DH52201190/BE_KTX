<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dorm_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admission_candidate_id')->constrained('admission_candidates')->cascadeOnDelete();
            $table->foreignId('registration_period_id')->nullable()->constrained('registration_periods')->nullOnDelete();
            $table->string('reservation_code')->nullable()->unique();
            $table->string('student_code')->nullable();
            $table->enum('status', ['submitted', 'approved', 'rejected', 'waitlisted', 'converted', 'expired', 'cancelled'])->default('submitted');
            $table->text('priority_note')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('admin_note')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('converted_registration_id')->nullable()->constrained('registrations')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dorm_reservations');
    }
};
