<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_requests', function (Blueprint $table) {
            if (Schema::hasColumn('maintenance_requests', 'note')) {
                $table->dropColumn('note');
            }
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('maintenance_requests', 'note')) {
                $table->text('note')->nullable()->after('pending_assignments');
            }
        });
    }
};
