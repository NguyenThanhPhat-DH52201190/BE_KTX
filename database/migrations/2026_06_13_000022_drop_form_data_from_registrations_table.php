<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * form_data duplicated information already stored in dedicated registration
     * columns and the linked student record. Code now rebuilds the form_data
     * shape from those columns, so the JSON column is redundant.
     */
    public function up(): void
    {
        if (! Schema::hasTable('registrations')) {
            return;
        }

        Schema::table('registrations', function (Blueprint $table) {
            if (Schema::hasColumn('registrations', 'form_data')) {
                $table->dropColumn('form_data');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('registrations')) {
            return;
        }

        Schema::table('registrations', function (Blueprint $table) {
            if (! Schema::hasColumn('registrations', 'form_data')) {
                $table->json('form_data')->nullable()->after('semester');
            }
        });
    }
};
