<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('student_support_requests')) {
            return;
        }

        Schema::table('student_support_requests', function (Blueprint $table) {
            if (Schema::hasColumn('student_support_requests', 'processed_by')) {
                $table->dropForeign(['processed_by']);
                $table->dropColumn('processed_by');
            }

            if (Schema::hasColumn('student_support_requests', 'processed_at')) {
                $table->dropColumn('processed_at');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('student_support_requests')) {
            return;
        }

        Schema::table('student_support_requests', function (Blueprint $table) {
            $table->foreignId('processed_by')->nullable()->constrained('accounts')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
        });
    }
};
