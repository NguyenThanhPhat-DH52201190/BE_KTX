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
        Schema::create('registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->string('semester');
            $table->string('school_year');
            $table->string('father_name');
            $table->string('father_birth_year');
            $table->string('father_job');
            $table->string('father_phone');
            $table->string('mother_name');
            $table->string('mother_birth_year');
            $table->string('mother_job');
            $table->string('mother_phone');
            $table->string('parent_address');
            $table->date('stay_from_date');
            $table->date('stay_to_date');
            $table->string('cccd_front_url')->nullable();
            $table->string('cccd_back_url')->nullable();
            $table->boolean('commitment_confirm')->default(false);
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->text('note')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('assigned_bed_id')->nullable()->constrained('beds')->nullOnDelete();
            $table->foreignId('assigned_room_id')->nullable()->constrained('rooms')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('registrations');
    }
};
