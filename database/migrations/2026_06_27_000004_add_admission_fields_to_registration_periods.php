<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registration_periods', function (Blueprint $table) {
            $table->unsignedTinyInteger('round_number')->nullable()->after('channel');
            $table->boolean('allow_admission_candidates')->default(false)->after('round_number');
            $table->boolean('requires_student_code')->default(true)->after('allow_admission_candidates');
        });
    }

    public function down(): void
    {
        Schema::table('registration_periods', function (Blueprint $table) {
            $table->dropColumn(['round_number', 'allow_admission_candidates', 'requires_student_code']);
        });
    }
};
