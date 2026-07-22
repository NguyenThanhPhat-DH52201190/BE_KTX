<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Step 1: Re-seed occupancy_periods from extension registration_periods
        // Delete first to avoid duplicates on re-run
        DB::table('occupancy_periods')->delete();

        $extensionPeriods = DB::table('registration_periods')
            ->where('period_type', 'extension')
            ->get();

        $idMapping = []; // registration_period_id → occupancy_period_id

        foreach ($extensionPeriods as $rp) {
            $newStatus = match ($rp->status) {
                'pending' => 'draft',
                'active'  => 'open',
                'closed'  => 'closed',
                default   => 'closed',
            };

            $newId = DB::table('occupancy_periods')->insertGetId([
                'name'                 => $rp->name,
                'start_date'           => $rp->start_date,
                'end_date'             => $rp->end_date,
                'extension_until_date' => null,
                'status'               => $newStatus,
                'description'          => null,
                'created_at'           => $rp->created_at,
                'updated_at'           => $rp->updated_at,
            ]);

            $idMapping[$rp->id] = $newId;
        }

        // Step 2: Add occupancy_period_id column (skip if already exists from partial run)
        if (! Schema::hasColumn('occupancy_extensions', 'occupancy_period_id')) {
            Schema::table('occupancy_extensions', function (Blueprint $table) {
                $table->unsignedBigInteger('occupancy_period_id')->nullable()->after('student_id');
            });
        }

        // Step 3: Populate occupancy_period_id from the new id mapping
        foreach ($idMapping as $oldId => $newId) {
            DB::table('occupancy_extensions')
                ->where('registration_period_id', $oldId)
                ->update(['occupancy_period_id' => $newId]);
        }

        // Step 4: Drop the old registration_period_id column + its FK and indexes
        // The composite unique index (occupancy_id, registration_period_id) is used by MySQL
        // as the backing index for the occupancy_id FK — so we must add a temporary index on
        // occupancy_id first, giving MySQL a new backing, before we can drop the old composite.
        if (Schema::hasColumn('occupancy_extensions', 'registration_period_id')) {

            // 4a: Add temp backing index so occupancy_id FK is not blocked
            $existingTmp = DB::select("SHOW INDEX FROM occupancy_extensions WHERE Key_name = 'occ_ext_occupancy_id_tmp'");
            if (empty($existingTmp)) {
                Schema::table('occupancy_extensions', function (Blueprint $table) {
                    $table->index(['occupancy_id'], 'occ_ext_occupancy_id_tmp');
                });
            }

            // 4b: Drop FK on registration_period_id (if still exists), then indexes, then column
            $hasFkReg = ! empty(DB::select(
                "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                   AND CONSTRAINT_NAME = 'occupancy_extensions_registration_period_id_foreign'",
                ['occupancy_extensions']
            ));

            Schema::table('occupancy_extensions', function (Blueprint $table) use ($hasFkReg) {
                if ($hasFkReg) {
                    $table->dropForeign(['registration_period_id']);
                }

                $hasOldUnique = ! empty(DB::select("SHOW INDEX FROM occupancy_extensions WHERE Key_name = 'occ_ext_occupancy_period_unique'"));
                if ($hasOldUnique) {
                    $table->dropUnique('occ_ext_occupancy_period_unique');
                }

                $hasOldStatus = ! empty(DB::select("SHOW INDEX FROM occupancy_extensions WHERE Key_name = 'occ_ext_status_period_idx'"));
                if ($hasOldStatus) {
                    $table->dropIndex('occ_ext_status_period_idx');
                }

                // Also drop the orphaned backing index if still present
                $hasRegIdx = ! empty(DB::select("SHOW INDEX FROM occupancy_extensions WHERE Key_name = 'occupancy_extensions_registration_period_id_foreign'"));
                if ($hasRegIdx) {
                    $table->dropIndex('occupancy_extensions_registration_period_id_foreign');
                }

                $table->dropColumn('registration_period_id');
            });

            // 4c: Create the final unique index BEFORE dropping temp
            // so occupancy_id FK always has a valid backing index
            $hasNewUnique = ! empty(DB::select("SHOW INDEX FROM occupancy_extensions WHERE Key_name = 'occ_ext_occupancy_period_unique'"));
            if (! $hasNewUnique) {
                Schema::table('occupancy_extensions', function (Blueprint $table) {
                    $table->unique(['occupancy_id', 'occupancy_period_id'], 'occ_ext_occupancy_period_unique');
                });
            }

            // 4d: Now safely drop the temp backing index
            $hasTmp = ! empty(DB::select("SHOW INDEX FROM occupancy_extensions WHERE Key_name = 'occ_ext_occupancy_id_tmp'"));
            if ($hasTmp) {
                Schema::table('occupancy_extensions', function (Blueprint $table) {
                    $table->dropIndex('occ_ext_occupancy_id_tmp');
                });
            }
        }

        // Step 5: Finalize occupancy_period_id — NOT NULL, FK on occupancy_periods, status index
        DB::statement('ALTER TABLE occupancy_extensions MODIFY occupancy_period_id BIGINT UNSIGNED NOT NULL');

        $hasFk = ! empty(DB::select(
            "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
               AND CONSTRAINT_NAME = 'occupancy_extensions_occupancy_period_id_foreign'",
            ['occupancy_extensions']
        ));
        if (! $hasFk) {
            Schema::table('occupancy_extensions', function (Blueprint $table) {
                $table->foreign('occupancy_period_id')
                      ->references('id')
                      ->on('occupancy_periods')
                      ->cascadeOnDelete();
            });
        }

        $hasStatusIdx = ! empty(DB::select("SHOW INDEX FROM occupancy_extensions WHERE Key_name = 'occ_ext_status_period_idx'"));
        if (! $hasStatusIdx) {
            Schema::table('occupancy_extensions', function (Blueprint $table) {
                $table->index(['status', 'occupancy_period_id'], 'occ_ext_status_period_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::table('occupancy_extensions', function (Blueprint $table) {
            $table->dropForeign(['occupancy_period_id']);
            $table->dropUnique('occ_ext_occupancy_period_unique');
            $table->dropIndex('occ_ext_status_period_idx');
            $table->dropColumn('occupancy_period_id');

            $table->foreignId('registration_period_id')
                  ->after('student_id')
                  ->constrained('registration_periods')
                  ->cascadeOnDelete();

            $table->unique(['occupancy_id', 'registration_period_id'], 'occ_ext_occupancy_period_unique');
            $table->index(['status', 'registration_period_id'], 'occ_ext_status_period_idx');
        });
    }
};
