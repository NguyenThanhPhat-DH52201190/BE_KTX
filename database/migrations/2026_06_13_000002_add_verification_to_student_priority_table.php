<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('student_priority')) {
            return;
        }

        Schema::table('student_priority', function (Blueprint $table) {
            if (! Schema::hasColumn('student_priority', 'evidence_url')) {
                $table->string('evidence_url')->nullable()->after('priority_criteria_id');
            }

            // Only 'verified' rows count toward score and tier.
            if (! Schema::hasColumn('student_priority', 'status')) {
                $table->enum('status', ['pending', 'verified', 'rejected'])
                    ->default('pending')
                    ->after('evidence_url');
            }

            if (! Schema::hasColumn('student_priority', 'verified_by')) {
                $table->foreignId('verified_by')
                    ->nullable()
                    ->after('status')
                    ->constrained('accounts')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('student_priority', 'verified_at')) {
                $table->timestamp('verified_at')->nullable()->after('verified_by');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('student_priority')) {
            return;
        }

        Schema::table('student_priority', function (Blueprint $table) {
            if (Schema::hasColumn('student_priority', 'verified_by')) {
                $table->dropConstrainedForeignId('verified_by');
            }

            foreach (['verified_at', 'status', 'evidence_url'] as $column) {
                if (Schema::hasColumn('student_priority', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
