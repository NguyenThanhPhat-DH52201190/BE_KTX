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
            $table->string('avatar')->nullable();
            $table->string('full_name');
            $table->string('gender');
            $table->string('class_name');
            $table->string('faculty');
            $table->string('phone');
            $table->string('email')->unique();
            $table->string('cccd')->unique();
            $table->string('permanent_address');
            $table->string('password');
            $table->string('parent_name');
            $table->string('parent_phone');
            $table->string('parent_relationship');
            $table->string('status')->default('active');
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
