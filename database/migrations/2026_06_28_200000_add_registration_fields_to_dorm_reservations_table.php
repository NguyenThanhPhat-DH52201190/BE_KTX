<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dorm_reservations', function (Blueprint $table) {
            $table->string('father_name')->nullable()->after('priority_note');
            $table->string('father_birth_year')->nullable()->after('father_name');
            $table->string('father_job')->nullable()->after('father_birth_year');
            $table->string('father_phone')->nullable()->after('father_job');
            $table->string('mother_name')->nullable()->after('father_phone');
            $table->string('mother_birth_year')->nullable()->after('mother_name');
            $table->string('mother_job')->nullable()->after('mother_birth_year');
            $table->string('mother_phone')->nullable()->after('mother_job');
            $table->string('parent_address')->nullable()->after('mother_phone');
            $table->boolean('commitment_confirm')->default(false)->after('parent_address');
        });
    }

    public function down(): void
    {
        Schema::table('dorm_reservations', function (Blueprint $table) {
            $table->dropColumn([
                'father_name', 'father_birth_year', 'father_job', 'father_phone',
                'mother_name', 'mother_birth_year', 'mother_job', 'mother_phone',
                'parent_address', 'commitment_confirm',
            ]);
        });
    }
};
