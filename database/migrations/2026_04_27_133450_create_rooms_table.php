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
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->string('building_code', 10);
            $table->string('room_number', 10);
            $table->integer('capacity')->default(14);
            $table->decimal('price_per_month', 10, 2);
            $table->string('status')->default('available');

            $table->unique(['building_code', 'room_number']);
            $table->foreign('building_code')->references('building_code')->on('buildings')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
