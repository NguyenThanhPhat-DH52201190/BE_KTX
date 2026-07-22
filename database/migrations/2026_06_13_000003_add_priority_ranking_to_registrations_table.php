<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('registrations')) {
            return;
        }

        Schema::table('registrations', function (Blueprint $table) {
            // Cached ranking values, computed once when the period is closed.
            if (! Schema::hasColumn('registrations', 'top_priority_tier')) {
                $table->tinyInteger('top_priority_tier')->unsigned()->default(99)->after('reason');
            }

            if (! Schema::hasColumn('registrations', 'total_priority_score')) {
                $table->integer('total_priority_score')->default(0)->after('top_priority_tier');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('registrations')) {
            return;
        }

        Schema::table('registrations', function (Blueprint $table) {
            foreach (['total_priority_score', 'top_priority_tier'] as $column) {
                if (Schema::hasColumn('registrations', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
