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
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->string('student_code')->unique();
            $table->string('full_name');
            $table->date('date_of_birth');
            $table->enum('gender', ['male', 'female'])->default('male');
            $table->string('class_name');
            $table->string('faculty');
            $table->string('course_year');
            $table->string('phone');
            $table->string('email')->unique();
            $table->string('cccd')->unique();
            $table->date('cccd_issued_date');
            $table->string('cccd_issued_place');
            $table->string('nationality');
            $table->string('ethnicity');
            $table->string('religion');
            $table->string('permanent_address');
            $table->string('avatar')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
            
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
