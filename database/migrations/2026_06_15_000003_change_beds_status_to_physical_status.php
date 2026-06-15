<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('beds')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE beds MODIFY status ENUM('empty','occupied','active','maintenance') NOT NULL DEFAULT 'active'");
        }

        DB::table('beds')
            ->whereIn('status', ['empty', 'occupied'])
            ->update(['status' => 'active']);

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE beds MODIFY status ENUM('active','maintenance') NOT NULL DEFAULT 'active'");
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('beds')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE beds MODIFY status ENUM('empty','occupied','active','maintenance') NOT NULL DEFAULT 'empty'");
        }

        DB::table('beds')
            ->where('status', 'active')
            ->update(['status' => 'empty']);

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE beds MODIFY status ENUM('empty','occupied','maintenance') NOT NULL DEFAULT 'empty'");
        }
    }
};
