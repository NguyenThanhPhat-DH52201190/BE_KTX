<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('faculty')->nullable()->change();
            $table->string('course_year')->nullable()->change();
            $table->string('phone')->nullable()->change();
            $table->string('email')->nullable()->change();
            $table->string('cccd')->nullable()->change();
            $table->date('cccd_issued_date')->nullable()->change();
            $table->string('cccd_issued_place')->nullable()->change();
            $table->string('nationality')->nullable()->change();
            $table->string('ethnicity')->nullable()->change();
            $table->string('religion')->nullable()->change();
            $table->string('permanent_address')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('faculty')->nullable(false)->change();
            $table->string('course_year')->nullable(false)->change();
            $table->string('phone')->nullable(false)->change();
            $table->string('email')->nullable(false)->change();
            $table->string('cccd')->nullable(false)->change();
            $table->date('cccd_issued_date')->nullable(false)->change();
            $table->string('cccd_issued_place')->nullable(false)->change();
            $table->string('nationality')->nullable(false)->change();
            $table->string('ethnicity')->nullable(false)->change();
            $table->string('religion')->nullable(false)->change();
            $table->string('permanent_address')->nullable(false)->change();
        });
    }
};
