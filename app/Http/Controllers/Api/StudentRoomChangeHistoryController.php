<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Occupancy;
use App\Models\Student;
use App\Services\RoomChangeLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StudentRoomChangeHistoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $account = $request->user();
        $studentId = $account?->student_id;

        if (!$studentId || !Student::find($studentId)) {
            return response()->json(['message' => 'Không tìm thấy sinh viên.'], 422);
        }

        $occupancies = Occupancy::query()
            ->with(['room.floor', 'bed', 'registration.period'])
            ->where('student_id', $studentId)
            ->orderByDesc('check_in_date')
            ->orderByDesc('id')
            ->get();

        $rows = DB::table('room_change_log as rcl')
            ->join('occupancy as occ', 'occ.id', '=', 'rcl.occupancy_id')
            ->leftJoin('rooms as old_r', 'old_r.id', '=', 'rcl.old_room_id')
            ->leftJoin('floors as old_f', 'old_f.id', '=', 'old_r.floor_id')
            ->leftJoin('beds as old_b', 'old_b.id', '=', 'rcl.old_bed_id')
            ->leftJoin('rooms as new_r', 'new_r.id', '=', 'rcl.new_room_id')
            ->leftJoin('floors as new_f', 'new_f.id', '=', 'new_r.floor_id')
            ->leftJoin('beds as new_b', 'new_b.id', '=', 'rcl.new_bed_id')
            ->where('occ.student_id', $studentId)
            ->where('rcl.transfer_reason', '!=', 'test')
            ->orderBy('rcl.transferred_at', 'asc')
            ->orderBy('rcl.id', 'asc')
            ->select([
                'rcl.id',
                'rcl.occupancy_id',
                'rcl.change_type',
                'rcl.change_source',
                'rcl.transfer_reason',
                'rcl.status',
                'rcl.is_temporary',
                'rcl.expected_return_date',
                'rcl.completed_at',
                'rcl.transferred_at',
                DB::raw("NULLIF(CONCAT(COALESCE(old_f.building_code,''), COALESCE(old_r.room_number,'')), '') as old_room_code"),
                DB::raw("COALESCE(old_b.bed_number, NULL) as old_bed_number"),
                DB::raw("NULLIF(CONCAT(COALESCE(new_f.building_code,''), COALESCE(new_r.room_number,'')), '') as new_room_code"),
                DB::raw("COALESCE(new_b.bed_number, NULL) as new_bed_number"),
            ])
            ->get()
            ->groupBy('occupancy_id');

        $chains = $this->buildRenewalChains($occupancies);

        $result = $chains
            ->map(function (Collection $chain) use ($rows) {
                // Chuỗi gia hạn đã sắp theo check_in_date tăng dần: đầu chuỗi = occupancy gốc, cuối chuỗi = kỳ hiện tại.
                $first = $chain->first();
                $last = $chain->last();

                $group = $chain->reduce(
                    fn (Collection $carry, Occupancy $occ) => $carry->merge($rows->get($occ->id, collect())),
                    collect()
                )->sortBy('transferred_at')->values();

                $initial = $group->filter(fn ($r) => RoomChangeLogService::isInitialAssignment($r));

                if ($initial->isNotEmpty()) {
                    $firstInitial = $initial->first();
                    $lastInitial = $initial->last();

                    $start = [
                        'transferred_at' => $firstInitial->transferred_at,
                        'room_code'      => $lastInitial->new_room_code,
                        'bed_number'     => $lastInitial->new_bed_number ? (string) $lastInitial->new_bed_number : null,
                    ];
                } else {
                    $start = [
                        'transferred_at' => $first->check_in_date,
                        'room_code'      => $this->roomCode($first),
                        'bed_number'     => $first->bed?->bed_number ? (string) $first->bed->bed_number : null,
                    ];
                }

                $initialIds = $initial->pluck('id')->all();

                $changes = $group
                    ->reject(fn ($r) => in_array($r->id, $initialIds, true))
                    ->sortByDesc('transferred_at')
                    ->values()
                    ->map(function ($r) {
                        return [
                            'id'                   => $r->id,
                            'label'                => RoomChangeLogService::buildLabel($r),
                            'change_type'          => $r->change_type,
                            'change_source'        => $r->change_source,
                            'transfer_reason'      => $r->transfer_reason,
                            'status'               => $r->status,
                            'is_temporary'         => (bool) $r->is_temporary,
                            'pending_return'       => (bool) $r->is_temporary && !$r->completed_at,
                            'expected_return_date' => $r->expected_return_date,
                            'transferred_at'       => $r->transferred_at,
                            'old_room_code'        => $r->old_room_code,
                            'old_bed_number'       => $r->old_bed_number ? (string) $r->old_bed_number : null,
                            'new_room_code'        => $r->new_room_code,
                            'new_bed_number'       => $r->new_bed_number ? (string) $r->new_bed_number : null,
                        ];
                    })
                    ->values()
                    ->all();

                return [
                    'group_id'           => $first->id,
                    'room_code'          => $this->roomCode($last),
                    'bed_number'         => $last->bed?->bed_number ? (string) $last->bed->bed_number : null,
                    'check_in_date'      => $first->check_in_date,
                    'check_out_date'     => $last->check_out_date,
                    'is_current'         => $last->status === 'ACTIVE',
                    'registration_period' => $this->registrationPeriodInfo($first),
                    'extension_count'    => $chain->count() - 1,
                    'start'              => $start,
                    'changes'            => $changes,
                ];
            })
            ->sortByDesc(fn ($g) => $g['check_in_date'])
            ->values()
            ->all();

        return response()->json(['occupancies' => $result]);
    }

    /**
     * Gộp các occupancy liên tiếp qua previous_occupancy_id (gia hạn lưu trú) thành từng chuỗi,
     * mỗi chuỗi sắp theo check_in_date tăng dần (gốc -> hiện tại).
     *
     * @param Collection<int, Occupancy> $occupancies
     * @return Collection<int, Collection<int, Occupancy>>
     */
    private function buildRenewalChains(Collection $occupancies): Collection
    {
        $byId = $occupancies->keyBy('id');
        $chains = [];

        foreach ($occupancies as $occ) {
            $current = $occ;
            $seen = [];

            while ($current->previous_occupancy_id
                && $byId->has($current->previous_occupancy_id)
                && !isset($seen[$current->id])) {
                $seen[$current->id] = true;
                $current = $byId->get($current->previous_occupancy_id);
            }

            $rootId = $current->id;
            $chains[$rootId][] = $occ;
        }

        return collect($chains)->map(
            fn (array $chain) => collect($chain)->sortBy([
                ['check_in_date', 'asc'],
                ['id', 'asc'],
            ])->values()
        );
    }

    private function roomCode(Occupancy $occ): ?string
    {
        $floor = $occ->room?->floor;

        if (!$floor || !$occ->room?->room_number) {
            return null;
        }

        return (string) $floor->building_code . (string) $occ->room->room_number;
    }

    /**
     * @return array{name: string, school_year: string|null, semester: int|string|null, channel: string|null}|null
     */
    private function registrationPeriodInfo(Occupancy $occ): ?array
    {
        $registration = $occ->registration;
        $period = $registration?->period;

        if (!$registration || !$period || !$period->name) {
            return null;
        }

        return [
            'name'        => $period->name,
            'school_year' => $period->school_year ?? $registration->school_year,
            'semester'    => $period->semester ?? $registration->semester,
            'channel'     => $period->channel,
        ];
    }

}
