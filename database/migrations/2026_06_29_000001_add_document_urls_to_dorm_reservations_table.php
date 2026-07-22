<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dorm_reservations', function (Blueprint $table) {
            $table->string('avatar_url')->nullable()->after('commitment_confirm');
            $table->string('cccd_front_url')->nullable()->after('avatar_url');
            $table->string('cccd_back_url')->nullable()->after('cccd_front_url');
        });
    }

    public function down(): void
    {
        Schema::table('dorm_reservations', function (Blueprint $table) {
            $table->dropColumn(['avatar_url', 'cccd_front_url', 'cccd_back_url']);
        });
    }
};
