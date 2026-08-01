<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OccupancyExtensionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                   => (int) $this->id,
            'occupancy_id'         => (int) $this->occupancy_id,
            'student_id'           => (int) $this->student_id,
            'occupancy_period_id'  => (int) $this->occupancy_period_id,
            'reason'               => $this->reason,
            'status'               => $this->status,
            'admin_note'           => $this->admin_note,
            'requested_at'         => $this->requested_at?->toISOString(),
            'approved_at'          => $this->approved_at?->toISOString(),
            'created_at'           => $this->created_at?->toISOString(),
            'updated_at'           => $this->updated_at?->toISOString(),

            'occupancy' => $this->whenLoaded('occupancy', function () {
                $occ = $this->occupancy;
                if (!$occ) return null;
                return [
                    'id'             => (int) $occ->id,
                    'status'         => $occ->status,
                    'check_in_date'  => $occ->check_in_date,
                    'check_out_date' => $occ->check_out_date,
                    'room' => $occ->relationLoaded('room') && $occ->room ? [
                        'id'            => (int) $occ->room->id,
                        'room_number'   => $occ->room->room_number,
                        'floor_number'  => $occ->room->relationLoaded('floor') ? ($occ->room->floor?->floor_number) : null,
                        'building_code' => $occ->room->relationLoaded('floor') ? ($occ->room->floor?->building_code) : null,
                        'building_name' => ($occ->room->relationLoaded('floor') && $occ->room->floor?->relationLoaded('building'))
                            ? ($occ->room->floor->building?->name)
                            : null,
                    ] : null,
                    'bed' => $occ->relationLoaded('bed') && $occ->bed ? [
                        'id'         => (int) $occ->bed->id,
                        'bed_number' => (int) $occ->bed->bed_number,
                    ] : null,
                ];
            }),

            'student' => $this->whenLoaded('student', function () {
                $s = $this->student;
                if (!$s) return null;
                return [
                    'id'           => (int) $s->id,
                    'student_code' => $s->student_code ?? '',
                    'full_name'    => $s->full_name ?? '',
                    'email'        => $s->email ?? '',
                    'phone'        => $s->phone ?? '',
                    'class_name'   => $s->class_name ?? '',
                    'faculty'      => $s->faculty ?? '',
                ];
            }),

            'occupancy_period' => $this->whenLoaded('occupancyPeriod', function () {
                $p = $this->occupancyPeriod;
                if (!$p) return null;
                return [
                    'id'                   => (int) $p->id,
                    'name'                 => $p->name,
                    'end_date'             => $p->end_date?->toDateString(),
                    'extension_until_date' => $p->extension_until_date?->toDateString(),
                ];
            }),
        ];
    }
}
