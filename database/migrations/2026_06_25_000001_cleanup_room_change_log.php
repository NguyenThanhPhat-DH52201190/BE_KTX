<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $total_before = DB::table('room_change_log')->count();
        $deleted = 0;

        // ── Rule 1: NULL occupancy_id ─────────────────────────────────────────
        $n = DB::table('room_change_log')->whereNull('occupancy_id')->delete();
        $deleted += $n;
        echo "Rule 1 (NULL occ_id): -{$n}\n";

        // ── Rule 2+3: RETURN records with no valid prior GO ───────────────────
        // A RETURN is valid only if there's a go record (is_temporary=1)
        // for the same occupancy with transferred_at BEFORE this return.
        $returns = DB::table('room_change_log')
            ->where('change_type', 'TEMPORARY_MAINTENANCE')
            ->where('is_temporary', 0)
            ->whereIn('transfer_reason', ['BED_MAINTENANCE_RETURN', 'ROOM_MAINTENANCE_RETURN'])
            ->get(['id', 'occupancy_id', 'transferred_at']);

        $orphanReturnIds = [];
        foreach ($returns as $ret) {
            $hasValidGo = DB::table('room_change_log')
                ->where('occupancy_id', $ret->occupancy_id)
                ->where('change_type', 'TEMPORARY_MAINTENANCE')
                ->where('is_temporary', 1)
                ->where('transferred_at', '<', $ret->transferred_at)
                ->exists();

            if (!$hasValidGo) {
                $orphanReturnIds[] = $ret->id;
            }
        }

        if ($orphanReturnIds) {
            $n = DB::table('room_change_log')->whereIn('id', $orphanReturnIds)->delete();
            $deleted += $n;
            echo "Rule 2+3 (orphan/reversed returns): -{$n} (IDs: " . implode(',', $orphanReturnIds) . ")\n";
        } else {
            echo "Rule 2+3: -0\n";
        }

        // ── Rule 4: Continuity — old location must match prev record's new ────
        // Run in passes until stable (cascading deletions).
        $passCount = 0;
        do {
            $passCount++;
            $discontinuousIds = [];

            $records = DB::select('
                SELECT id, occupancy_id, old_room_id, old_bed_id, new_room_id, new_bed_id,
                       transferred_at, transfer_reason
                FROM room_change_log
                WHERE occupancy_id IS NOT NULL
                ORDER BY occupancy_id, transferred_at ASC, id ASC
            ');

            $byOcc = [];
            foreach ($records as $r) {
                $byOcc[$r->occupancy_id][] = $r;
            }

            foreach ($byOcc as $rows) {
                if (count($rows) < 2) {
                    continue;
                }
                // Build a "current" chain: if we delete record i,
                // record i+1 checks against the last surviving record.
                $chain = [$rows[0]]; // first record always kept as baseline

                for ($i = 1; $i < count($rows); $i++) {
                    $prev = end($chain);
                    $curr = $rows[$i];

                    // Skip continuity check when either side has null location
                    // (can't validate, keep the record)
                    if ($prev->new_room_id === null || $curr->old_room_id === null) {
                        $chain[] = $curr;
                        continue;
                    }

                    if ((int) $prev->new_room_id !== (int) $curr->old_room_id
                        || (string) $prev->new_bed_id !== (string) $curr->old_bed_id
                    ) {
                        $discontinuousIds[] = $curr->id;
                        // Do NOT add to chain — next record checks against $prev
                    } else {
                        $chain[] = $curr;
                    }
                }
            }

            if ($discontinuousIds) {
                $n = DB::table('room_change_log')->whereIn('id', $discontinuousIds)->delete();
                $deleted += $n;
                echo "Rule 4 pass {$passCount} (discontinuous): -{$n} (IDs: " . implode(',', $discontinuousIds) . ")\n";
            }
        } while (!empty($discontinuousIds)); // repeat until no more found

        $total_after = DB::table('room_change_log')->count();

        echo "\n=== Kết quả ===\n";
        echo "Trước: {$total_before}\n";
        echo "Đã xóa: {$deleted}\n";
        echo "Còn lại: {$total_after}\n";
    }

    public function down(): void
    {
        // Không thể rollback data deletion
    }
};
