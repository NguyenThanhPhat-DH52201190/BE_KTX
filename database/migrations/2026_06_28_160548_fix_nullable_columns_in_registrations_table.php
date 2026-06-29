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
        Schema::table('registrations', function (Blueprint $table) {
            $table->string('father_name')->nullable()->change();
            $table->string('father_birth_year')->nullable()->change();
            $table->string('father_job')->nullable()->change();
            $table->string('father_phone')->nullable()->change();
            $table->string('mother_name')->nullable()->change();
            $table->string('mother_birth_year')->nullable()->change();
            $table->string('mother_job')->nullable()->change();
            $table->string('mother_phone')->nullable()->change();
            $table->string('parent_address')->nullable()->change();
            $table->date('stay_from_date')->nullable()->change();
            $table->date('stay_to_date')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('registrations', function (Blueprint $table) {
            $table->string('father_name')->nullable(false)->change();
            $table->string('father_birth_year')->nullable(false)->change();
            $table->string('father_job')->nullable(false)->change();
            $table->string('father_phone')->nullable(false)->change();
            $table->string('mother_name')->nullable(false)->change();
            $table->string('mother_birth_year')->nullable(false)->change();
            $table->string('mother_job')->nullable(false)->change();
            $table->string('mother_phone')->nullable(false)->change();
            $table->string('parent_address')->nullable(false)->change();
            $table->date('stay_from_date')->nullable(false)->change();
            $table->date('stay_to_date')->nullable(false)->change();
        });
    }
};
