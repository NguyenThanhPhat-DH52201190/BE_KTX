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
        Schema::create('room_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('occupancy_id')->constrained('occupancy')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('current_room_id')->constrained('rooms')->cascadeOnDelete();
            $table->foreignId('current_bed_id')->nullable()->constrained('beds')->nullOnDelete();
            $table->foreignId('desired_room_id')->constrained('rooms')->cascadeOnDelete();
            $table->foreignId('desired_bed_id')->nullable()->constrained('beds')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignId('approved_by')->nullable()->constrained('accounts')->nullOnDelete();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('room_change_requests');
    }
};
