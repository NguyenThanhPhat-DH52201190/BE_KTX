<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('fee_discount_policies');

        Schema::create('fee_discount_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('priority_criteria_id')
                ->unique()
                ->constrained('priority_criteria')
                ->cascadeOnDelete();
            $table->decimal('discount_percent', 5, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_discount_policies');
    }
};
