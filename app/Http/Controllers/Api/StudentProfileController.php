<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Blacklist;
use App\Models\Registration;
use App\Models\Student;
use App\Models\StudentPriority;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentProfileController extends Controller
{
    private function getImageUrl(?string $path): ?string
    {
        if (empty($path)) {
            return null;
        }

        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }

        $cleanPath = preg_replace('#^/?(api/)?storage/#', '', ltrim($path, '/'));

        $isProduction = app()->environment('production') || env('RAILWAY_ENVIRONMENT') === 'production';

        if ($isProduction) {
            return url('/api/storage/' . $cleanPath);
        }

        return url('/storage/' . $cleanPath);
    }

    public function show(Request $request): JsonResponse
    {
        $account = $request->user();
        $student = Student::with('province')->find($account->student_id);

        if (!$student) {
            return response()->json(['message' => 'Không tìm thấy sinh viên.'], 422);
        }

        $latestRegistration = Registration::where('student_id', $student->id)
            ->with(['occupancy.room.floor', 'occupancy.bed'])
            ->orderByDesc('created_at')
            ->first();

        $activeRegistration = Registration::where('student_id', $student->id)
            ->where('status', 'approved')
            ->with(['occupancy.room.floor', 'occupancy.bed'])
            ->orderByDesc('created_at')
            ->first();

        $occupancy = $activeRegistration?->occupancy;
        $residence = null;

        if ($occupancy && in_array($occupancy->status, ['ACTIVE', 'ROOM_CONFIRMED', 'PENDING_PAYMENT'])) {
            $floor = $occupancy->room?->floor;
            $residence = [
                'building_code'  => $floor?->building_code,
                'floor_number'   => $floor?->floor_number,
                'room_number'    => $occupancy->room?->room_number,
                'bed_number'     => $occupancy->bed?->bed_number,
                'status'         => $occupancy->status,
                'check_in_date'  => $occupancy->check_in_date,
                'check_out_date' => $occupancy->check_out_date,
            ];
        }

        $blacklist = Blacklist::where('student_id', $student->id)->latest('id')->first();

        $priorityCriteria = StudentPriority::with(['criteria', 'evidences'])
            ->where('student_id', $student->id)
            ->get()
            ->map(function ($p) {
                $urls = $p->evidences
                    ->map(fn ($e) => $this->getImageUrl($e->file_url))
                    ->values()
                    ->all();
                if (empty($urls) && $p->evidence_url) {
                    $urls = [$this->getImageUrl($p->evidence_url)];
                }

                return [
                    'id'            => $p->id,
                    'criteria_id'   => $p->priority_criteria_id,
                    'code'          => $p->criteria?->code,
                    'name'          => $p->criteria?->name,
                    'evidence_urls' => $urls,
                    'status'        => $p->status,
                ];
            })
            ->all();

        return response()->json([
            'student' => [
                'id'                 => $student->id,
                'student_code'       => $student->student_code,
                'full_name'          => $student->full_name,
                'email'              => $student->email,
                'date_of_birth'      => $student->date_of_birth,
                'gender'             => $student->gender,
                'class_name'         => $student->class_name,
                'faculty'            => $student->faculty,
                'course_year'        => $student->course_year,
                'phone'              => $student->phone,
                'cccd'               => $student->cccd,
                'cccd_issued_date'   => $student->cccd_issued_date,
                'cccd_issued_place'  => $student->cccd_issued_place,
                'nationality'        => $student->nationality,
                'ethnicity'          => $student->ethnicity,
                'religion'           => $student->religion,
                'permanent_address'  => $student->permanent_address,
                'province_code'      => $student->province_code,
                'province_name'      => $student->province?->name,
                'avatar_url'         => $this->getImageUrl($latestRegistration?->avatar_url ?? $student->avatar),
                'status'             => $student->status,
                'academic_status'    => $student->academic_status,
                'current_year'       => $student->current_year,
            ],
            // Đọc trực tiếp từ students — đây là nguồn dữ liệu chính (dữ liệu tĩnh gắn
            // với con người), không còn đọc qua $latestRegistration (đơn gần nhất) vì
            // sẽ luôn null ở lần nộp đơn đầu tiên, không giải quyết đúng gốc vấn đề.
            'family' => [
                'father_name'       => $student->father_name,
                'father_birth_year' => $student->father_birth_year,
                'father_job'        => $student->father_job,
                'father_phone'      => $student->father_phone,
                'mother_name'       => $student->mother_name,
                'mother_birth_year' => $student->mother_birth_year,
                'mother_job'        => $student->mother_job,
                'mother_phone'      => $student->mother_phone,
                'parent_address'    => $student->parent_address,
                'emergency_contact_name'         => $student->emergency_contact_name,
                'emergency_contact_phone'        => $student->emergency_contact_phone,
                'emergency_contact_relationship' => $student->emergency_contact_relationship,
            ],
            'residence' => $residence,
            'documents' => [
                'cccd_front_url' => $this->getImageUrl($latestRegistration?->cccd_front_url),
                'cccd_back_url'  => $this->getImageUrl($latestRegistration?->cccd_back_url),
            ],
            'priority_criteria' => $priorityCriteria,
            'blacklist' => $blacklist ? [
                'reason'     => $blacklist->reason,
                'source'     => $blacklist->source,
                'created_at' => $blacklist->created_at,
            ] : null,
        ]);
    }
}
