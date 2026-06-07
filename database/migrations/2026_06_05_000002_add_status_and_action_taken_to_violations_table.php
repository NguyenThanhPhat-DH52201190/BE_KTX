<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('violations')) {
            return;
        }

        Schema::table('violations', function (Blueprint $table) {
            if (!Schema::hasColumn('violations', 'status')) {
                $table->string('status')->default('pending')->after('note');
            }

            if (!Schema::hasColumn('violations', 'action_taken')) {
                $table->text('action_taken')->nullable()->after('status');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('violations')) {
            return;
        }

        Schema::table('violations', function (Blueprint $table) {
            if (Schema::hasColumn('violations', 'action_taken')) {
                $table->dropColumn('action_taken');
            }

            if (Schema::hasColumn('violations', 'status')) {
                $table->dropColumn('status');
            }
        });
    }
};
