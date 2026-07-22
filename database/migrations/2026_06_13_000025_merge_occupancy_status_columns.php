<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Merge the two overlapping occupancy state columns into a single business
     * lifecycle `status` enum: PROPOSED -> ROOM_CONFIRMED -> ACTIVE ->
     * COMPLETED / TERMINATED / CANCELLED.
     *
     * The operational sub-states the old code packed into `status`
     * (bed-approval pending/rejected, checkout requested) are preserved in two
     * dedicated columns so the admin bed-approval workflow keeps working:
     *   - bed_approval_status enum('pending','approved','rejected') NULL
     *   - checkout_requested  tinyint(1) default 0
     */
    public function up(): void
    {
        if (! Schema::hasTable('occupancy') || DB::getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('occupancy', function (Blueprint $table) {
            if (! Schema::hasColumn('occupancy', 'bed_approval_status')) {
                $table->enum('bed_approval_status', ['pending', 'approved', 'rejected'])
                    ->nullable()
                    ->after('status');
            }
            if (! Schema::hasColumn('occupancy', 'checkout_requested')) {
                $table->boolean('checkout_requested')->default(false)->after('bed_approval_status');
            }
        });

        // Work on a free-form column while remapping to avoid enum constraints.
        DB::statement('ALTER TABLE occupancy MODIFY status VARCHAR(32) NULL');

        // Derive the bed-approval sub-state from the old status value.
        DB::statement("UPDATE occupancy SET bed_approval_status='approved'
            WHERE status IN ('OCCUPIED','ACTIVE','active','occupied','CHECKOUT','checked_out','forced_checkout','checkout_requested')");
        DB::statement("UPDATE occupancy SET bed_approval_status='pending'
            WHERE status IN ('PENDING','pending')");
        DB::statement("UPDATE occupancy SET bed_approval_status='rejected'
            WHERE status IN ('rejected')");
        DB::statement("UPDATE occupancy SET checkout_requested=1
            WHERE status IN ('checkout_requested')");

        // Remap the lifecycle status itself.
        DB::statement("UPDATE occupancy SET status='ACTIVE'
            WHERE status IN ('OCCUPIED','occupied','ACTIVE','active','checkout_requested')");
        DB::statement("UPDATE occupancy SET status='ROOM_CONFIRMED'
            WHERE status IN ('CONFIRMED','assigned','PENDING','pending','rejected')");
        DB::statement("UPDATE occupancy SET status='COMPLETED'
            WHERE status IN ('CHECKOUT','checked_out')");
        DB::statement("UPDATE occupancy SET status='TERMINATED'
            WHERE status IN ('forced_checkout')");

        // Rows with an invalid/empty old value: infer from activity history.
        DB::statement("UPDATE occupancy o
            LEFT JOIN (
                SELECT DISTINCT occupancy_id FROM activities WHERE UPPER(action_taken) = 'FORCED_CHECKOUT'
            ) a ON a.occupancy_id = o.id
            SET o.status = CASE WHEN a.occupancy_id IS NOT NULL THEN 'TERMINATED' ELSE 'COMPLETED' END
            WHERE o.status IS NULL OR o.status = '' OR o.status NOT IN ('PROPOSED','ROOM_CONFIRMED','ACTIVE','COMPLETED','TERMINATED','CANCELLED')");

        // Fold the legacy occupancy_status dimension into the lifecycle.
        if (Schema::hasColumn('occupancy', 'occupancy_status')) {
            DB::statement("UPDATE occupancy SET status='COMPLETED'
                WHERE occupancy_status IN ('completed','renewed') AND status NOT IN ('TERMINATED','CANCELLED')");
            DB::statement("UPDATE occupancy SET status='TERMINATED'
                WHERE occupancy_status='terminated'");
        }

        // Ended occupancies that had a bed were approved tenants.
        DB::statement("UPDATE occupancy SET bed_approval_status='approved'
            WHERE bed_approval_status IS NULL AND status IN ('COMPLETED','TERMINATED') AND bed_id IS NOT NULL");

        DB::statement("ALTER TABLE occupancy MODIFY status
            ENUM('PROPOSED','ROOM_CONFIRMED','ACTIVE','COMPLETED','TERMINATED','CANCELLED') NOT NULL DEFAULT 'PROPOSED'");

        if (Schema::hasColumn('occupancy', 'occupancy_status')) {
            Schema::table('occupancy', function (Blueprint $table) {
                $table->dropColumn('occupancy_status');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('occupancy') || DB::getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('occupancy', function (Blueprint $table) {
            if (! Schema::hasColumn('occupancy', 'occupancy_status')) {
                $table->enum('occupancy_status', ['active', 'completed', 'terminated', 'renewed'])
                    ->default('active')
                    ->after('reason');
            }
        });

        // Best-effort restore of the legacy lifecycle dimension.
        DB::statement("UPDATE occupancy SET occupancy_status='completed' WHERE status='COMPLETED'");
        DB::statement("UPDATE occupancy SET occupancy_status='terminated' WHERE status='TERMINATED'");
        DB::statement("UPDATE occupancy SET occupancy_status='active' WHERE status IN ('PROPOSED','ROOM_CONFIRMED','ACTIVE','CANCELLED')");

        // Best-effort restore to the OLD enum vocabulary. The pre-merge code
        // wrote lowercase values that MySQL stored as '' (invalid), so the
        // original fine-grained states are unrecoverable; map every value to a
        // valid member of the old enum to avoid truncation.
        DB::statement('ALTER TABLE occupancy MODIFY status VARCHAR(32) NULL');
        DB::statement("UPDATE occupancy SET status='OCCUPIED' WHERE status='ACTIVE'");
        DB::statement("UPDATE occupancy SET status='CONFIRMED' WHERE status IN ('ROOM_CONFIRMED','CANCELLED')");
        DB::statement("UPDATE occupancy SET status='CHECKOUT' WHERE status IN ('COMPLETED','TERMINATED')");
        // PROPOSED already matches the old enum and is left unchanged.
        DB::statement("ALTER TABLE occupancy MODIFY status ENUM('OCCUPIED','ACTIVE','PROPOSED','CONFIRMED','CHECKOUT') NOT NULL");

        Schema::table('occupancy', function (Blueprint $table) {
            if (Schema::hasColumn('occupancy', 'checkout_requested')) {
                $table->dropColumn('checkout_requested');
            }
            if (Schema::hasColumn('occupancy', 'bed_approval_status')) {
                $table->dropColumn('bed_approval_status');
            }
        });
    }
};
