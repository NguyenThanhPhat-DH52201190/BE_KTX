<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_priority_evidences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_priority_id')
                  ->constrained('reservation_priorities')
                  ->cascadeOnDelete();
            $table->text('file_url');
            $table->string('original_name')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_priority_evidences');
    }
};
