<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Bed;
use App\Models\Building;
use App\Models\Floor;
use App\Models\Occupancy;
use App\Models\Registration;
use App\Models\Room;
use App\Models\Student;
use App\Services\AwsFaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Tests\TestCase;

class StudentFaceSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Account::create([
            'username'  => 'admin_test',
            'password'  => Hash::make('secret12345'),
            'role'      => 'admin',
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'sanctum');
    }

    private function studentPayload(string $studentCode, string $fullName): array
    {
        return [
            'student_code'       => $studentCode,
            'full_name'          => $fullName,
            'date_of_birth'      => '2004-01-01',
            'gender'             => 'male',
            'class_name'         => 'D22_TH00',
            'faculty'            => 'CNTT',
            'course_year'        => 'D22',
            'phone'              => '0900000000',
            'email'              => strtolower($studentCode) . '@student.stu.edu.vn',
            'cccd'               => str_pad((string) crc32($studentCode), 12, '0', STR_PAD_LEFT),
            'cccd_issued_date'   => '2022-01-01',
            'cccd_issued_place'  => 'TP.HCM',
            'nationality'        => 'Việt Nam',
            'ethnicity'          => 'Kinh',
            'religion'           => 'Không',
            'permanent_address'  => 'TP.HCM',
            'avatar'             => 'students/avatar/' . strtolower($studentCode) . '.jpg',
            'status'             => 'active',
        ];
    }

    private function registrationPayload(int $studentId, string $status): array
    {
        return [
            'student_id'         => $studentId,
            'father_name'        => 'Cha',
            'father_birth_year'  => '1980',
            'father_job'         => 'Không',
            'father_phone'       => '0900000001',
            'mother_name'        => 'Mẹ',
            'mother_birth_year'  => '1982',
            'mother_job'         => 'Không',
            'mother_phone'       => '0900000002',
            'parent_address'     => 'TP.HCM',
            'stay_from_date'     => '2026-06-20',
            'stay_to_date'       => '2027-06-20',
            'status'             => $status,
        ];
    }

    private function makeActiveOccupancyStudent(string $studentCode): Student
    {
        Building::firstOrCreate(['building_code' => 'A'], ['name' => 'Tòa A', 'status' => 'active']);
        $floor = Floor::firstOrCreate(['building_code' => 'A', 'floor_number' => 1], ['status' => 'active']);
        $room = Room::create(['floor_id' => $floor->id, 'room_number' => '101', 'capacity' => 4, 'price_per_month' => 0, 'status' => 'active']);
        $bed = Bed::create(['room_id' => $room->id, 'bed_number' => 1, 'status' => 'active']);

        $student = Student::create($this->studentPayload($studentCode, 'Nguyễn Văn A'));

        $registration = Registration::create($this->registrationPayload($student->id, 'approved'));

        Occupancy::create([
            'registration_id'     => $registration->id,
            'student_id'          => $student->id,
            'room_id'             => $room->id,
            'bed_id'              => $bed->id,
            'status'              => 'ACTIVE',
            'bed_approval_status' => 'approved',
        ]);

        return $student;
    }

    public function test_returns_matching_students_who_are_listed_in_occupancy_management(): void
    {
        $student = $this->makeActiveOccupancyStudent('SV001');

        $mock = Mockery::mock(AwsFaceService::class);
        $mock->shouldReceive('searchByImage')
            ->once()
            ->andReturn([
                ['external_image_id' => (string) $student->id, 'similarity' => 92.5],
            ]);
        $this->app->instance(AwsFaceService::class, $mock);

        $response = $this->postJson('/api/admin/students/face-search', [
            'image' => UploadedFile::fake()->image('face.jpg'),
        ]);

        $response->assertOk();
        $response->assertJsonPath('results.0.student.id', $student->id);
        $response->assertJsonPath('results.0.student.occupancy_status', 'ACTIVE');
        $response->assertJsonPath('results.0.similarity', 92.5);
    }

    public function test_excludes_student_not_listed_in_occupancy_management(): void
    {
        // Sinh viên có đơn approved nhưng CHƯA có occupancy (chưa từng xếp phòng/lưu trú)
        // => không được trang "Quản lý lưu trú" liệt kê => phải bị loại khỏi kết quả face-search.
        $student = Student::create($this->studentPayload('SV002', 'Trần Thị B'));

        Registration::create($this->registrationPayload($student->id, 'approved'));

        $mock = Mockery::mock(AwsFaceService::class);
        $mock->shouldReceive('searchByImage')
            ->once()
            ->andReturn([
                ['external_image_id' => (string) $student->id, 'similarity' => 98.0],
            ]);
        $this->app->instance(AwsFaceService::class, $mock);

        $response = $this->postJson('/api/admin/students/face-search', [
            'image' => UploadedFile::fake()->image('face.jpg'),
        ]);

        $response->assertOk();
        $response->assertExactJson(['results' => []]);
    }

    public function test_returns_empty_results_when_no_match(): void
    {
        $mock = Mockery::mock(AwsFaceService::class);
        $mock->shouldReceive('searchByImage')->once()->andReturn([]);
        $this->app->instance(AwsFaceService::class, $mock);

        $response = $this->postJson('/api/admin/students/face-search', [
            'image' => UploadedFile::fake()->image('face.jpg'),
        ]);

        $response->assertOk();
        $response->assertExactJson(['results' => []]);
    }

    public function test_rejects_invalid_file_format(): void
    {
        $response = $this->postJson('/api/admin/students/face-search', [
            'image' => UploadedFile::fake()->create('face.txt', 10, 'text/plain'),
        ]);

        $response->assertStatus(422);
    }
}
