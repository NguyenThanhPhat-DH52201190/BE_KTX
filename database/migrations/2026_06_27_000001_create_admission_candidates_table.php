<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admission_candidates', function (Blueprint $table) {
            $table->id();
            $table->string('admission_code')->unique();
            $table->string('admission_code_suffix', 10)->nullable();
            $table->string('expected_student_code')->nullable();
            $table->string('full_name');
            $table->date('date_of_birth');
            $table->enum('gender', ['male', 'female'])->nullable();
            $table->string('cccd', 20)->nullable()->unique();
            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable();
            $table->text('permanent_address')->nullable();
            $table->string('major_code', 30)->nullable();
            $table->string('major_name')->nullable();
            $table->string('course_year', 20)->nullable();
            $table->string('school_year', 20)->nullable();
            $table->enum('status', ['admitted', 'enrolled', 'cancelled'])->default('admitted');
            $table->timestamp('enrolled_at')->nullable();
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_candidates');
    }
};
