<?php

namespace Tests\Unit;

use App\Models\PriorityCriteria;
use App\Models\Registration;
use App\Models\Student;
use App\Models\StudentPriority;
use App\Services\PriorityRankingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PriorityRankingTest extends TestCase
{
    use RefreshDatabase;

    private PriorityRankingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PriorityRankingService();
    }

    /**
     * Mandatory case: A has only UT01 (tier 1, 100 pts); B has UT04 + UT06
     * (top tier 2, total 110 pts). A must rank ABOVE B because tier 1 < tier 2,
     * even though A's total score is lower. Score must never beat tier.
     */
    public function test_higher_tier_beats_higher_total_score(): void
    {
        $ut01 = $this->criteria('UT01', tier: 1, score: 100);
        $ut04 = $this->criteria('UT04', tier: 2, score: 80);
        $ut06 = $this->criteria('UT06', tier: 3, score: 30);

        $periodId = $this->period();

        $studentA = $this->student('DH00000001', 'Sinh viên A');
        $studentB = $this->student('DH00000002', 'Sinh viên B');

        $regA = $this->registration($studentA->id, $periodId);
        $regB = $this->registration($studentB->id, $periodId);

        // A: only UT01, verified.
        $this->verifiedPriority($studentA->id, $ut01->id);

        // B: UT04 + UT06, both verified (total 110, top tier 2).
        $this->verifiedPriority($studentB->id, $ut04->id);
        $this->verifiedPriority($studentB->id, $ut06->id);

        // Sanity: an UNVERIFIED tier-1 row on B must NOT promote B's tier.
        StudentPriority::create([
            'student_id' => $studentB->id,
            'priority_criteria_id' => $ut01->id,
            'status' => 'pending',
        ]);

        $result = $this->service->rankPeriod($periodId, availableBeds: 1);

        // Cached values are correct and ignore the unverified row.
        $this->assertSame(1, $regA->fresh()->top_priority_tier);
        $this->assertSame(100, $regA->fresh()->total_priority_score);
        $this->assertSame(2, $regB->fresh()->top_priority_tier);
        $this->assertSame(110, $regB->fresh()->total_priority_score);

        // Ranking order: A before B.
        $rankedIds = $result['ranked']->pluck('id')->all();
        $this->assertSame([$regA->id, $regB->id], $rankedIds);

        // 1 bed available -> A approved, B waitlisted.
        $this->assertSame([$regA->id], $result['approved']->pluck('id')->all());
        $this->assertSame([$regB->id], $result['waitlist']->pluck('id')->all());
    }

    private function criteria(string $code, int $tier, int $score): PriorityCriteria
    {
        return PriorityCriteria::create([
            'code' => $code,
            'name' => $code,
            'tier' => $tier,
            'priority_score' => $score,
            'is_active' => true,
        ]);
    }

    private function verifiedPriority(int $studentId, int $criteriaId): StudentPriority
    {
        return StudentPriority::create([
            'student_id' => $studentId,
            'priority_criteria_id' => $criteriaId,
            'status' => 'verified',
            'verified_at' => now(),
        ]);
    }

    private function period(): int
    {
        return DB::table('registration_periods')->insertGetId([
            'name' => 'Đợt test',
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
            'status' => 'processing',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function student(string $code, string $name): Student
    {
        return Student::create([
            'student_code' => $code,
            'full_name' => $name,
            'date_of_birth' => '2004-01-01',
            'gender' => 'male',
            'class_name' => 'D22_TH00',
            'faculty' => 'CNTT',
            'course_year' => 'D22',
            'phone' => '0900000000',
            'email' => strtolower($code) . '@student.stu.edu.vn',
            'cccd' => str_pad((string) crc32($code), 12, '0', STR_PAD_LEFT),
            'cccd_issued_date' => '2022-01-01',
            'cccd_issued_place' => 'TP.HCM',
            'nationality' => 'Việt Nam',
            'ethnicity' => 'Kinh',
            'religion' => 'Không',
            'permanent_address' => 'TP.HCM',
            'status' => 'active',
        ]);
    }

    private function registration(int $studentId, int $periodId): Registration
    {
        return Registration::create([
            'student_id' => $studentId,
            'registration_period_id' => $periodId,
            'father_name' => 'Cha',
            'father_birth_year' => '1980',
            'father_job' => 'Không',
            'father_phone' => '0900000001',
            'mother_name' => 'Mẹ',
            'mother_birth_year' => '1982',
            'mother_job' => 'Không',
            'mother_phone' => '0900000002',
            'parent_address' => 'TP.HCM',
            'stay_from_date' => '2026-06-20',
            'stay_to_date' => '2027-06-20',
            'status' => 'pending',
        ]);
    }
}
