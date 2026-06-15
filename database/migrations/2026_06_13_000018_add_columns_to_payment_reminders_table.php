<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payment_reminders')) {
            return;
        }

        Schema::table('payment_reminders', function (Blueprint $table) {
            if (! Schema::hasColumn('payment_reminders', 'student_id')) {
                $table->foreignId('student_id')
                    ->nullable()
                    ->after('bill_id')
                    ->constrained('students')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('payment_reminders', 'due_amount')) {
                $table->decimal('due_amount', 12, 2)->nullable()->after('reminder_level');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('payment_reminders')) {
            return;
        }

        Schema::table('payment_reminders', function (Blueprint $table) {
            if (Schema::hasColumn('payment_reminders', 'due_amount')) {
                $table->dropColumn('due_amount');
            }
            if (Schema::hasColumn('payment_reminders', 'student_id')) {
                $table->dropConstrainedForeignId('student_id');
            }
        });
    }
};
