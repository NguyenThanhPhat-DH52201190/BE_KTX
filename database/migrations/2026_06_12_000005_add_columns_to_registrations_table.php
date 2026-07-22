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
        if (!Schema::hasTable('registrations')) {
            return;
        }

        Schema::table('registrations', function (Blueprint $table) {
            if (!Schema::hasColumn('registrations', 'registration_type')) {
                $table->enum('registration_type', ['new', 'renewal', 'emergency'])
                    ->default('new')
                    ->after('status');
            }

            if (!Schema::hasColumn('registrations', 'priority_score')) {
                $table->integer('priority_score')->default(0)->after('registration_type');
            }

            if (!Schema::hasColumn('registrations', 'auto_approved')) {
                $table->boolean('auto_approved')->default(false)->after('priority_score');
            }

            if (!Schema::hasColumn('registrations', 'auto_approval_reason')) {
                $table->string('auto_approval_reason')->nullable()->after('auto_approved');
            }

            if (!Schema::hasColumn('registrations', 'registration_period_id')) {
                $table->foreignId('registration_period_id')
                    ->nullable()
                    ->constrained('registration_periods')
                    ->nullOnDelete()
                    ->after('auto_approval_reason');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('registrations')) {
            return;
        }

        Schema::table('registrations', function (Blueprint $table) {
            if (Schema::hasColumn('registrations', 'registration_period_id')) {
                $table->dropConstrainedForeignId('registration_period_id');
            }

            if (Schema::hasColumn('registrations', 'auto_approval_reason')) {
                $table->dropColumn('auto_approval_reason');
            }

            if (Schema::hasColumn('registrations', 'auto_approved')) {
                $table->dropColumn('auto_approved');
            }

            if (Schema::hasColumn('registrations', 'priority_score')) {
                $table->dropColumn('priority_score');
            }

            if (Schema::hasColumn('registrations', 'registration_type')) {
                $table->dropColumn('registration_type');
            }
        });
    }
};
