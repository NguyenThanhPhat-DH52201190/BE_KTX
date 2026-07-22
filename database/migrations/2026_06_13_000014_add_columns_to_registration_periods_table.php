<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('registration_periods')) {
            return;
        }

        Schema::table('registration_periods', function (Blueprint $table) {
            if (! Schema::hasColumn('registration_periods', 'channel')) {
                $table->enum('channel', ['main', 'rolling'])->default('main')->after('status');
            }
            if (! Schema::hasColumn('registration_periods', 'school_year')) {
                $table->string('school_year', 20)->nullable()->after('channel');
            }
            if (! Schema::hasColumn('registration_periods', 'semester')) {
                $table->string('semester', 20)->nullable()->after('school_year');
            }
            if (! Schema::hasColumn('registration_periods', 'bed_selection_days')) {
                $table->integer('bed_selection_days')->nullable()->after('semester');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('registration_periods')) {
            return;
        }

        Schema::table('registration_periods', function (Blueprint $table) {
            foreach (['bed_selection_days', 'semester', 'school_year', 'channel'] as $column) {
                if (Schema::hasColumn('registration_periods', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
