<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_priorities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dorm_reservation_id')
                  ->constrained('dorm_reservations')
                  ->cascadeOnDelete();
            $table->foreignId('priority_criteria_id')
                  ->constrained('priority_criteria');
            $table->enum('status', ['pending', 'verified', 'rejected'])->default('pending');
            $table->foreignId('verified_by')
                  ->nullable()
                  ->constrained('accounts')
                  ->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->unique(['dorm_reservation_id', 'priority_criteria_id'], 'res_prio_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_priorities');
    }
};
