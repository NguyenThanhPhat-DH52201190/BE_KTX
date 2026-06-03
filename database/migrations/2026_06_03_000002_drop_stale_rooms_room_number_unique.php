<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('rooms')) {
            return;
        }

        $indexes = DB::select("SHOW INDEX FROM rooms WHERE Key_name = 'rooms_building_code_room_number_unique'");

        if (!empty($indexes)) {
            DB::statement('ALTER TABLE rooms DROP INDEX rooms_building_code_room_number_unique');
        }
    }

    public function down(): void
    {
        // The current room uniqueness rule is floor_id + room_number.
    }
};
