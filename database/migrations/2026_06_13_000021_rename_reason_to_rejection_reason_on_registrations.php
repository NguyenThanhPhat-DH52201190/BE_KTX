<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * registrations.reason already stores the rejection reason, so rename it to
     * rejection_reason (keeping the data) and keep `note` as the admin note.
     */
    public function up(): void
    {
        if (! Schema::hasTable('registrations')) {
            return;
        }

        if (Schema::hasColumn('registrations', 'reason')
            && ! Schema::hasColumn('registrations', 'rejection_reason')) {
            Schema::table('registrations', function (Blueprint $table) {
                $table->renameColumn('reason', 'rejection_reason');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('registrations')) {
            return;
        }

        if (Schema::hasColumn('registrations', 'rejection_reason')
            && ! Schema::hasColumn('registrations', 'reason')) {
            Schema::table('registrations', function (Blueprint $table) {
                $table->renameColumn('rejection_reason', 'reason');
            });
        }
    }
};
