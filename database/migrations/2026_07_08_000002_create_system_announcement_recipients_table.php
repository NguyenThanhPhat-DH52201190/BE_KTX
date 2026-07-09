<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_announcement_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('system_announcement_id');
            $table->foreignId('student_id');
            $table->foreignId('notification_id')->nullable();
            $table->enum('email_status', ['pending', 'sent', 'failed', 'skipped'])->default('skipped');
            $table->timestamp('email_sent_at')->nullable();
            $table->text('email_error')->nullable();
            $table->timestamps();

            $table->unique(['system_announcement_id', 'student_id'], 'sar_ann_student_idx');
            $table->index(['system_announcement_id', 'email_status'], 'sar_ann_email_idx');
            $table->index('student_id', 'sar_student_idx');
            $table->index('notification_id', 'sar_notification_idx');

            $table->foreign('system_announcement_id', 'sar_ann_fk')
                ->references('id')
                ->on('system_announcements')
                ->cascadeOnDelete();
            $table->foreign('student_id', 'sar_student_fk')
                ->references('id')
                ->on('students')
                ->cascadeOnDelete();
            $table->foreign('notification_id', 'sar_notification_fk')
                ->references('id')
                ->on('notifications')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_announcement_recipients');
    }
};
