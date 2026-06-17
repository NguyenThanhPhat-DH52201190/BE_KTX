<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_priority_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_priority_id')
                ->constrained('student_priority')
                ->cascadeOnDelete();
            $table->string('file_url');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_priority_evidence');
    }
};
