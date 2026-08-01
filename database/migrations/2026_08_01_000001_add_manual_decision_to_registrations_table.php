<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registrations', function (Blueprint $table) {
            if (! Schema::hasColumn('registrations', 'decision_source')) {
                $table->string('decision_source')->nullable()->after('auto_decision_reason');
            }
            if (! Schema::hasColumn('registrations', 'manual_decision')) {
                $table->string('manual_decision')->nullable()->after('decision_source');
            }
            if (! Schema::hasColumn('registrations', 'manual_decision_reason')) {
                $table->text('manual_decision_reason')->nullable()->after('manual_decision');
            }
        });
    }

    public function down(): void
    {
        Schema::table('registrations', function (Blueprint $table) {
            foreach (['decision_source', 'manual_decision', 'manual_decision_reason'] as $column) {
                if (Schema::hasColumn('registrations', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
