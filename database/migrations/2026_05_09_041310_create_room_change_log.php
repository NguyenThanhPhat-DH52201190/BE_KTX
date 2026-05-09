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
        Schema::create('room_change_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->foreignId('registration_id')->nullable()->constrained('registrations')->nullOnDelete();
            $table->foreignId('old_room_id')->nullable()->constrained('rooms')->nullOnDelete();
            $table->foreignId('old_bed_id')->nullable()->constrained('beds')->nullOnDelete();
            $table->foreignId('new_room_id')->nullable()->constrained('rooms')->nullOnDelete();
            $table->foreignId('new_bed_id')->nullable()->constrained('beds')->nullOnDelete();
            $table->string('transfer_reason')->nullable();
            $table->timestamp('transferred_at')->nullable();


        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('room_change_log');
    }
};
