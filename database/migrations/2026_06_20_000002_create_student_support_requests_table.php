<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('student_support_requests')) {
            return;
        }

        Schema::create('student_support_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->enum('request_type', [
                'roommate_request',
                'complaint',
                'suggestion',
                'maintenance_report',
                'other',
            ]);
            $table->string('title');
            $table->text('content');
            $table->string('attachment_url')->nullable();
            $table->enum('status', [
                'pending',
                'processing',
                'approved',
                'rejected',
                'completed',
            ])->default('pending');
            $table->text('admin_note')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('accounts')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['student_id', 'status'], 'student_support_requests_student_status_index');
            $table->index('request_type', 'student_support_requests_request_type_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_support_requests');
    }
};
