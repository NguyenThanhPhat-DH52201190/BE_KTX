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
        Schema::create('electricity_bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('registration_id')->constrained('registrations')->cascadeOnDelete();
            $table->string('month_year');
            $table->integer('usage_kwh');
            $table->decimal('unit_price', 10, 2);
            $table->decimal('amount', 12, 2);
            $table->date('due_date');
            $table->string('payment_method')->nullable();
            $table->string('transaction_code')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->enum('status', ['unpaid', 'paid', 'overdue'])->default('unpaid');
            $table->timestamps();
           
            
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('electricity_bills');
    }
};
