<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('floors')) {
            Schema::create('floors', function (Blueprint $table) {
                $table->id();
                $table->string('building_code', 10);
                $table->integer('floor_number');
                $table->integer('total_rooms')->default(0);
                $table->string('gender')->nullable();
                $table->enum('status', ['active', 'maintenance'])->default('active');

                $table->unique(['building_code', 'floor_number']);
                $table->foreign('building_code')->references('building_code')->on('buildings')->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('rooms')) {
            return;
        }

        if (Schema::hasColumn('rooms', 'building_code') && !Schema::hasColumn('rooms', 'floor_id')) {
            Schema::table('rooms', function (Blueprint $table) {
                $table->foreignId('floor_id')->nullable()->after('id');
            });

            $buildingCodes = DB::table('rooms')
                ->whereNotNull('building_code')
                ->select('building_code')
                ->distinct()
                ->pluck('building_code');

            foreach ($buildingCodes as $buildingCode) {
                $existingFloor = DB::table('floors')
                    ->where('building_code', $buildingCode)
                    ->where('floor_number', 1)
                    ->first();

                if ($existingFloor) {
                    $floorId = $existingFloor->id;
                } else {
                    $floorId = DB::table('floors')->insertGetId([
                        'building_code' => $buildingCode,
                        'floor_number' => 1,
                        'total_rooms' => DB::table('rooms')->where('building_code', $buildingCode)->count(),
                        'gender' => null,
                        'status' => 'active',
                    ]);
                }

                DB::table('rooms')
                    ->where('building_code', $buildingCode)
                    ->update(['floor_id' => $floorId]);
            }

            if (Schema::hasColumn('rooms', 'building_code')) {
                try {
                    Schema::table('rooms', function (Blueprint $table) {
                        $table->dropUnique('rooms_building_code_room_number_unique');
                    });
                } catch (\Throwable $e) {
                    // Ignore if the unique index does not exist in the current database.
                }

                try {
                    Schema::table('rooms', function (Blueprint $table) {
                        $table->dropForeign(['building_code']);
                    });
                } catch (\Throwable $e) {
                    // Ignore if the foreign key does not exist in the current database.
                }

                DB::statement('ALTER TABLE rooms DROP COLUMN building_code');
            }
        }

        if (Schema::hasColumn('rooms', 'floor_id')) {
            try {
                Schema::table('rooms', function (Blueprint $table) {
                    $table->unique(['floor_id', 'room_number']);
                });
            } catch (\Throwable $e) {
                // Ignore if the unique index already exists.
            }

            try {
                Schema::table('rooms', function (Blueprint $table) {
                    $table->foreign('floor_id')->references('id')->on('floors')->cascadeOnDelete();
                });
            } catch (\Throwable $e) {
                // Ignore if the foreign key already exists.
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('rooms') && Schema::hasColumn('rooms', 'floor_id')) {
            try {
                Schema::table('rooms', function (Blueprint $table) {
                    $table->dropForeign(['floor_id']);
                });
            } catch (\Throwable $e) {
                // Ignore if the foreign key does not exist.
            }

            try {
                Schema::table('rooms', function (Blueprint $table) {
                    $table->dropUnique('rooms_floor_id_room_number_unique');
                });
            } catch (\Throwable $e) {
                // Ignore if the unique index does not exist.
            }

            DB::statement('ALTER TABLE rooms ADD COLUMN building_code VARCHAR(10) NULL');
        }

        if (Schema::hasTable('floors')) {
            Schema::dropIfExists('floors');
        }
    }
};
