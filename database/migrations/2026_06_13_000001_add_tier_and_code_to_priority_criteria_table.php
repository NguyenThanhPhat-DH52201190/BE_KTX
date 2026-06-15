<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('priority_criteria')) {
            return;
        }

        Schema::table('priority_criteria', function (Blueprint $table) {
            // Stable identifier for each policy criterion (UT01..UT06).
            if (! Schema::hasColumn('priority_criteria', 'code')) {
                $table->string('code')->nullable()->unique()->after('id');
            }

            // Policy tier: 1 = highest priority, 99 = no priority (fixed by policy).
            if (! Schema::hasColumn('priority_criteria', 'tier')) {
                $table->tinyInteger('tier')->unsigned()->default(99)->after('priority_score');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('priority_criteria')) {
            return;
        }

        Schema::table('priority_criteria', function (Blueprint $table) {
            if (Schema::hasColumn('priority_criteria', 'tier')) {
                $table->dropColumn('tier');
            }

            if (Schema::hasColumn('priority_criteria', 'code')) {
                $table->dropUnique(['code']);
                $table->dropColumn('code');
            }
        });
    }
};
