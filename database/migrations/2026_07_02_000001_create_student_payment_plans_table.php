<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('student_payment_plans')) {
            return;
        }

        Schema::create('student_payment_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->enum('type', ['installment', 'discount']);
            $table->boolean('is_active')->default(true);
            $table->decimal('discount_percent', 5, 2)->nullable();
            $table->string('reason')->nullable();
            $table->foreignId('support_request_id')->nullable()->constrained('student_support_requests')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->string('created_by')->nullable();
            $table->string('deactivated_by')->nullable();
            $table->timestamps();

            $table->index(['student_id', 'type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_payment_plans');
    }
};
