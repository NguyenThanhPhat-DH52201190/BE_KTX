<?php

namespace Tests\Feature;

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
use Mockery;
use Tests\TestCase;

class StudentFaceSearchTest extends TestCase
{
    use RefreshDatabase;

    private function makeActiveOccupancyStudent(string $studentCode): Student
    {
        Building::firstOrCreate(['building_code' => 'A'], ['name' => 'Tòa A', 'status' => 'active']);
        $floor = Floor::firstOrCreate(['building_code' => 'A', 'floor_number' => 1], ['status' => 'active']);
        $room = Room::create(['floor_id' => $floor->id, 'room_number' => '101', 'capacity' => 4, 'price_per_month' => 0, 'status' => 'active']);
        $bed = Bed::create(['room_id' => $room->id, 'bed_number' => 1, 'status' => 'occupied']);

        $student = Student::create([
            'student_code' => $studentCode,
            'full_name'    => 'Nguyễn Văn A',
            'avatar'       => 'students/avatar/a.jpg',
            'status'       => 'active',
        ]);

        $registration = Registration::create([
            'student_id' => $student->id,
            'status'     => 'approved',
        ]);

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
        $student = Student::create([
            'student_code' => 'SV002',
            'full_name'    => 'Trần Thị B',
            'avatar'       => 'students/avatar/b.jpg',
            'status'       => 'active',
        ]);

        Registration::create([
            'student_id' => $student->id,
            'status'     => 'approved',
        ]);

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
