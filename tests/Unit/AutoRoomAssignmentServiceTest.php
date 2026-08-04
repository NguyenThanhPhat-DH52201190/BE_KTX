<?php

namespace Tests\Unit;

use App\Models\Bed;
use App\Models\Building;
use App\Models\Floor;
use App\Models\Occupancy;
use App\Models\Registration;
use App\Models\RegistrationPeriod;
use App\Models\Room;
use App\Models\Student;
use App\Services\AutoRoomAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AutoRoomAssignmentServiceTest extends TestCase
{
    use RefreshDatabase;

    private AutoRoomAssignmentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->service = new AutoRoomAssignmentService();
    }

    /**
     * TC03: gender must match floor.gender, and the tier-1 applicant must be
     * placed even when both applicants are eligible for the same-gender room.
     */
    public function test_assigns_rooms_matching_gender_and_prioritises_lower_tier(): void
    {
        $period = $this->period();

        $maleFloor = $this->floor('A', 1, 'male');
        $femaleFloor = $this->floor('A', 2, 'female');
        $maleRoom = $this->room($maleFloor->id, '101', 2);
        $femaleRoom = $this->room($femaleFloor->id, '201', 1);

        $maleStudent = $this->student('DH10000001', 'male');
        $femaleStudent = $this->student('DH10000002', 'female');

        $regMale = $this->registration($maleStudent->id, $period->id, tier: 1, score: 100);
        $regFemale = $this->registration($femaleStudent->id, $period->id, tier: 2, score: 50);

        $result = $this->service->run($period->id);

        $this->assertSame(2, $result['assigned']);
        $this->assertSame(0, $result['no_room']);

        $occupancyMale = Occupancy::where('registration_id', $regMale->id)->first();
        $occupancyFemale = Occupancy::where('registration_id', $regFemale->id)->first();

        $this->assertSame('PROPOSED', $occupancyMale->status);
        $this->assertSame($maleRoom->id, $occupancyMale->room_id);
        $this->assertSame('PROPOSED', $occupancyFemale->status);
        $this->assertSame($femaleRoom->id, $occupancyFemale->room_id);
    }

    /**
     * TC04: when no room matches the applicant's gender, the registration is
     * reported as "no_room" and any stale PROPOSED occupancy is deleted so the
     * registration reverts to "unassigned" instead of pointing at a full room.
     */
    public function test_reports_no_room_and_clears_stale_proposal_when_no_room_available(): void
    {
        $period = $this->period();

        $femaleFloor = $this->floor('B', 1, 'female');
        $femaleRoom = $this->room($femaleFloor->id, '301', 1);

        $femaleStudent = $this->student('DH10000003', 'female');
        $registration = $this->registration($femaleStudent->id, $period->id, tier: 1, score: 100);

        // Room already fully occupied by an unrelated ACTIVE occupancy.
        $existingBed = Bed::where('room_id', $femaleRoom->id)->first();
        Occupancy::create([
            'registration_id' => null,
            'student_id'      => $this->student('DH10000004', 'female')->id,
            'room_id'         => $femaleRoom->id,
            'bed_id'          => $existingBed->id,
            'status'          => 'ACTIVE',
        ]);

        // Simulate a stale PROPOSED occupancy left over from a previous run.
        $staleProposal = Occupancy::create([
            'registration_id' => $registration->id,
            'student_id'      => $femaleStudent->id,
            'room_id'         => $femaleRoom->id,
            'bed_id'          => null,
            'status'          => 'PROPOSED',
        ]);

        $result = $this->service->run($period->id);

        $this->assertSame(0, $result['assigned']);
        $this->assertSame(1, $result['no_room']);
        $this->assertNull(Occupancy::find($staleProposal->id));
    }

    /**
     * TC05: confirming proposals flips PROPOSED -> ROOM_CONFIRMED for every
     * proposed occupancy in the period.
     */
    public function test_confirm_proposals_promotes_proposed_to_room_confirmed(): void
    {
        $period = $this->period();
        $floor = $this->floor('C', 1, 'male');
        $this->room($floor->id, '401', 2);

        $student = $this->student('DH10000005', 'male');
        $registration = $this->registration($student->id, $period->id, tier: 1, score: 100);

        $this->service->run($period->id);

        $result = $this->service->confirmProposals($period->id);

        $this->assertSame(1, $result['confirmed']);
        $occupancy = Occupancy::where('registration_id', $registration->id)->first();
        $this->assertSame('ROOM_CONFIRMED', $occupancy->status);
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function period(): RegistrationPeriod
    {
        return RegistrationPeriod::create([
            'name'                      => 'Đợt test',
            'start_date'                => '2026-06-01',
            'end_date'                  => '2026-06-30',
            'stay_start_date'           => '2026-07-01',
            'stay_end_date'             => '2027-06-30',
            'status'                    => 'processing',
            'initial_payment_due_days'  => 30,
        ]);
    }

    private function floor(string $buildingCode, int $floorNumber, string $gender): Floor
    {
        Building::firstOrCreate(['building_code' => $buildingCode], ['name' => "Tòa {$buildingCode}", 'status' => 'active']);

        return Floor::create([
            'building_code' => $buildingCode,
            'floor_number'  => $floorNumber,
            'gender'        => $gender,
            'status'        => 'active',
        ]);
    }

    private function room(int $floorId, string $roomNumber, int $capacity): Room
    {
        $room = Room::create([
            'floor_id'        => $floorId,
            'room_number'     => $roomNumber,
            'capacity'        => $capacity,
            'price_per_month' => 0,
            'status'          => 'active',
        ]);

        for ($i = 1; $i <= $capacity; $i++) {
            Bed::create(['room_id' => $room->id, 'bed_number' => $i, 'status' => 'active']);
        }

        return $room;
    }

    private function student(string $code, string $gender): Student
    {
        return Student::create([
            'student_code'      => $code,
            'full_name'         => 'Sinh viên ' . $code,
            'date_of_birth'     => '2004-01-01',
            'gender'            => $gender,
            'class_name'        => 'D22_TH00',
            'faculty'           => 'CNTT',
            'course_year'       => 'D22',
            'phone'             => '0900000000',
            'email'             => strtolower($code) . '@student.stu.edu.vn',
            'cccd'              => str_pad((string) crc32($code), 12, '0', STR_PAD_LEFT),
            'cccd_issued_date'  => '2022-01-01',
            'cccd_issued_place' => 'TP.HCM',
            'nationality'       => 'Việt Nam',
            'ethnicity'         => 'Kinh',
            'religion'          => 'Không',
            'permanent_address' => 'TP.HCM',
            'status'            => 'active',
        ]);
    }

    private function registration(int $studentId, int $periodId, int $tier, int $score): Registration
    {
        return Registration::create([
            'student_id'             => $studentId,
            'registration_period_id' => $periodId,
            'father_name'            => 'Cha',
            'father_birth_year'      => '1980',
            'father_job'             => 'Không',
            'father_phone'           => '0900000001',
            'mother_name'            => 'Mẹ',
            'mother_birth_year'      => '1982',
            'mother_job'             => 'Không',
            'mother_phone'           => '0900000002',
            'parent_address'         => 'TP.HCM',
            'stay_from_date'         => '2026-07-01',
            'stay_to_date'           => '2027-06-30',
            'status'                 => 'approved',
            'top_priority_tier'      => $tier,
            'total_priority_score'   => $score,
        ]);
    }
}
