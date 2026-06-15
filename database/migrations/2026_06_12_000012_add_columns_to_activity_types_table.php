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
        if (!Schema::hasTable('activity_types')) {
            return;
        }

        Schema::table('activity_types', function (Blueprint $table) {
            if (!Schema::hasColumn('activity_types', 'category')) {
                $table->enum('category', ['positive', 'negative'])->default('negative')->after('description');
            }

            if (!Schema::hasColumn('activity_types', 'points')) {
                $table->integer('points')->default(0)->after('category');
            }

            if (!Schema::hasColumn('activity_types', 'created_at')) {
                $table->timestamps();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('activity_types')) {
            return;
        }

        Schema::table('activity_types', function (Blueprint $table) {
            if (Schema::hasColumn('activity_types', 'points')) {
                $table->dropColumn('points');
            }

            if (Schema::hasColumn('activity_types', 'category')) {
                $table->dropColumn('category');
            }

            if (Schema::hasColumn('activity_types', 'updated_at')) {
                $table->dropColumn('created_at', 'updated_at');
            }
        });
    }
};
