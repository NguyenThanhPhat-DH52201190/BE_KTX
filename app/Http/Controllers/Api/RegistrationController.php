<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Registration\StoreRegistrationRequest;
use Illuminate\Http\Request;
use App\Models\Registration;
use App\Models\Account;
use App\Models\Student;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RegistrationController extends Controller
{
    public function index()
    {
        $registrations = Registration::with(['student', 'student.account'])->get();
        
        // Map dữ liệu để thêm email vào
        return $registrations->map(function ($registration) {
            return [
                'id' => $registration->id,
                'student_id' => $registration->student_id,
                'email' => $registration->student?->email ?? $registration->student?->account?->email ?? '',
                'status' => $registration->status,
                'semester' => $registration->semester,
                'cccd_front_url' => $registration->cccd_front_url,
                'cccd_back_url' => $registration->cccd_back_url,
                'reason' => $registration->reason,
                'assigned_room_id' => $registration->assigned_room_id,
                'note' => $registration->note,
                'created_at' => $registration->created_at,
                'student' => $registration->student,
            ];
        });
    }

    public function store(StoreRegistrationRequest $request)
    {
        $account = Account::where('email', $request->email)->first();

        if (!$account) {
            return response()->json(['message' => 'Không tìm thấy user'], 404);
        }

        $currentStudent = $account->student_id ? Student::find($account->student_id) : null;
        $data = $request->validated();

        if ($request->hasFile('avatar')) {
            $data['avatar'] = $request->file('avatar')->store('students/avatar', 'public');
        }

        if ($request->hasFile('cccd_front')) {
            $data['cccd_front_url'] = $request->file('cccd_front')->store('registrations/cccd', 'public');
        }

        if ($request->hasFile('cccd_back')) {
            $data['cccd_back_url'] = $request->file('cccd_back')->store('registrations/cccd', 'public');
        }

        try {
            $result = DB::transaction(function () use ($account, $currentStudent, $data) {
                $studentPayload = [
                    'student_code' => $data['student_code'],
                    'avatar' => $data['avatar'] ?? optional($currentStudent)->avatar,
                    'full_name' => $data['full_name'],
                    'gender' => $data['gender'],
                    'class_name' => $data['class_name'],
                    'faculty' => $data['faculty'],
                    'phone' => $data['phone'],
                    'email' => $account->email,
                    'cccd' => $data['cccd'],
                    'permanent_address' => $data['permanent_address'],
                    'password' => $account->password,
                    'parent_name' => $data['parent_name'],
                    'parent_phone' => $data['parent_phone'],
                    'parent_relationship' => $data['parent_relationship'],
                    'status' => 'active',
                ];

                if ($currentStudent) {
                    $currentStudent->update($studentPayload);
                    $student = $currentStudent;
                } else {
                    $student = Student::create($studentPayload);
                    $account->student_id = $student->id;
                    $account->save();
                }

                $hasPendingSameSemester = Registration::where('student_id', $student->id)
                    ->where('semester', $data['semester'])
                    ->where('status', 'pending')
                    ->lockForUpdate()
                    ->exists();

                if ($hasPendingSameSemester) {
                    throw new RuntimeException('DUPLICATE_PENDING_REGISTRATION');
                }

                $registration = Registration::create([
                    'student_id' => $student->id,
                    'cccd_front_url' => $data['cccd_front_url'] ?? null,
                    'cccd_back_url' => $data['cccd_back_url'] ?? null,
                    'semester' => $data['semester'],
                    'status' => 'pending',
                ]);

                return [
                    'student' => $student,
                    'registration' => $registration,
                ];
            });
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === 'DUPLICATE_PENDING_REGISTRATION') {
                return response()->json([
                    'message' => 'Bạn đã có đơn chờ duyệt cho học kỳ này'
                ], 409);
            }

            throw $exception;
        }

        return response()->json([
            'message' => 'Đăng ký thành công',
            'data' => $result
        ], 201);
    }


    public function getMyRegistration(Request $request)
    {
        $email = $request->query('email');

        $account = Account::where('email', $email)->first();

        if (!$account) {
            return response()->json(['message' => 'Không tìm thấy user'], 404);
        }

        $registration = Registration::with(['student', 'student.account'])
            ->where('student_id', $account->student_id)
            ->latest('id')
            ->first();

        if (!$registration) {
            return response()->json(null);
        }

        return response()->json([
            'id' => $registration->id,
            'student_id' => $registration->student_id,
            'email' => $registration->student?->email ?? $email ?? '',
            'status' => $registration->status,
            'semester' => $registration->semester,
            'cccd_front_url' => $registration->cccd_front_url,
            'cccd_back_url' => $registration->cccd_back_url,
            'reason' => $registration->reason,
            'assigned_room_id' => $registration->assigned_room_id,
            'note' => $registration->note,
            'created_at' => $registration->created_at,
            'student' => $registration->student,
        ]);
    }


    public function approve($id, Request $request)
    {
        $registration = Registration::find($id);

        if (!$registration) {
            return response()->json(['message' => 'Không tìm thấy đơn'], 404);
        }

        $registration->status = 'approved';
        $registration->save();

        return response()->json([
            'message' => 'Đã duyệt'
        ]);
    }

    public function reject($id, Request $request)
    {
        $request->validate([
            'rejectionReason' => 'required|string|max:500',
        ]);

        $registration = Registration::find($id);

        if (!$registration) {
            return response()->json(['message' => 'Không tìm thấy đơn'], 404);
        }

        $registration->status = 'rejected';
        $registration->reason = $request->rejectionReason;
        $registration->save();

        return response()->json([
            'message' => 'Đã từ chối'
        ]);
    }

    public function show($id)
    {
        $registration = Registration::with(['student', 'student.account'])->find($id);

        if (!$registration) {
            return response()->json(['message' => 'Không tìm thấy'], 404);
        }

        return response()->json([
            'id' => $registration->id,
            'student_id' => $registration->student_id,
            'email' => $registration->student?->email ?? $registration->student?->account?->email ?? '',
            'status' => $registration->status,
            'semester' => $registration->semester,
            'cccd_front_url' => $registration->cccd_front_url,
            'cccd_back_url' => $registration->cccd_back_url,
            'reason' => $registration->reason,
            'assigned_room_id' => $registration->assigned_room_id,
            'note' => $registration->note,
            'created_at' => $registration->created_at,
            'student' => $registration->student,
        ]);
    }
}
