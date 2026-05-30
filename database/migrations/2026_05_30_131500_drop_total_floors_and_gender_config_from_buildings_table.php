<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class DropTotalFloorsAndGenderConfigFromBuildingsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('buildings')) {
            return;
        }

        // Drop columns if they exist
        if (Schema::hasColumn('buildings', 'total_floors') || Schema::hasColumn('buildings', 'gender_config')) {
            Schema::table('buildings', function (Blueprint $table) {
                if (Schema::hasColumn('buildings', 'total_floors')) {
                    $table->dropColumn('total_floors');
                }
                if (Schema::hasColumn('buildings', 'gender_config')) {
                    $table->dropColumn('gender_config');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('buildings')) {
            return;
        }

        Schema::table('buildings', function (Blueprint $table) {
            if (!Schema::hasColumn('buildings', 'total_floors')) {
                $table->integer('total_floors')->nullable();
            }
            if (!Schema::hasColumn('buildings', 'gender_config')) {
                $table->string('gender_config')->nullable();
            }
        });
    }
}
