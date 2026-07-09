<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('content');
            $table->string('type')->default('general');
            $table->string('priority')->default('normal');
            $table->string('target_type')->default('active_students');
            $table->text('target_value')->nullable();
            $table->boolean('send_web')->default(true);
            $table->boolean('send_email')->default(false);
            $table->string('attachment_path')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->enum('status', ['draft', 'scheduled', 'sending', 'sent', 'failed'])->default('draft');
            $table->foreignId('created_by')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('accounts')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'scheduled_at']);
            $table->index(['type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_announcements');
    }
};
