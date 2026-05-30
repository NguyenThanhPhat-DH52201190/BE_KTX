<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use App\Models\Account;
use App\Models\Student;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\ResetPasswordOtpRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\SendResetLinkRequest;
use App\Http\Requests\Auth\SendOtpRequest;
use App\Http\Requests\Auth\CheckEmailRequest;
use App\Http\Requests\Auth\CheckStudentCodeRequest;


class AuthController extends Controller
{
    public function register(RegisterRequest $request)
    {
        // Lấy sinh viên theo MSSV (RegisterRequest đã xác thực tồn tại trong students)
        $student = Student::where('student_code', $request->student_code)->first();

        if (!$student) {
            return response()->json(['message' => 'MSSV không tồn tại'], 400);
        }

        if (empty($student->email)) {
            return response()->json(['message' => 'Sinh viên chưa có email trong hồ sơ. Vui lòng liên hệ quản trị.'], 400);
        }

        // Kiểm tra xem đã có account cho student này chưa
        if (Account::where('student_id', $student->id)->exists()) {
            return response()->json(['message' => 'Tài khoản cho MSSV này đã tồn tại.'], 400);
        }

        $payload = [
            'password' => Hash::make($request->password),
            'role' => 'student',
            'student_id' => $student->id,
        ];

        $account = Account::create($payload);

        return response()->json([
            'message' => 'Đăng ký thành công',
            'account' => $account
        ], 201);
    }

    public function login(LoginRequest $request)
    {
        // Hỗ trợ đăng nhập bằng student_code hoặc email của sinh viên
        if ($request->filled('student_code')) {
            $student = Student::where('student_code', $request->student_code)->first();
        } else {
            $student = Student::where('email', $request->email)->first();
        }

        $account = $student?->account;

        if (!$account || !Hash::check($request->password, $account->password)) {
            return response()->json(['message' => 'Sai thông tin đăng nhập hoặc mật khẩu'], 401);
        }

        $token = $account->createToken('auth_token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $account->id,
                'email' => $student?->email,
                'role' => $account->role,
                'student_id' => $account->student_id,
                'student_code' => $student?->student_code,
                'studentCode' => $student?->student_code,
                'full_name' => $student?->full_name,
                'fullName' => $student?->full_name,
            ]
        ]);
    }

    // Quên mật khẩu
    public function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $student = Student::where('email', $request->email)->first();

        if (!$student || !$student->account) {
            return response()->json(['message' => 'Email chưa được đăng ký'], 404);
        }

        $token = Str::random(64);

        DB::table('password_resets')->updateOrInsert(
            ['email' => $request->email],
            [
                'email' => $request->email,
                'token' => $token,
                'created_at' => now(),
            ]
        );

        Mail::send([], [], function ($message) use ($request, $token) {
            $message->to($request->email)
                ->subject('TRƯỜNG ĐH CÔNG NGHỆ SÀI GÒN - ĐẶT LẠI MẬT KHẨU')
                ->html("<p>Link/token đặt lại mật khẩu của bạn: {$token}</p>");
        });

        return response()->json([
            'message' => 'Đã gửi email đặt lại mật khẩu'
        ]);
    }

    public function checkEmail(CheckEmailRequest $request)
    {
        $exists = Student::where('email', $request->email)->exists();

        return response()->json([
            'exists' => $exists
        ]);
    }

    public function checkStudentCode(CheckStudentCodeRequest $request)
    {
        // Trả về thông tin sinh viên nếu có
        $student = Student::where('student_code', $request->student_code)->first();

        if (!$student) {
            return response()->json(['exists' => false]);
        }

        return response()->json([
            'exists' => true,
            'student' => [
                'student_code' => $student->student_code,
                'email' => $student->email,
                'full_name' => $student->full_name,
                'date_of_birth' => $student->date_of_birth,
                'gender' => $student->gender,
                'class_name' => $student->class_name,
                'faculty' => $student->faculty,
                'course_year' => $student->course_year,
                'phone' => $student->phone,
                'cccd' => $student->cccd,
                'cccd_issued_date' => $student->cccd_issued_date,
                'cccd_issued_place' => $student->cccd_issued_place,
                'nationality' => $student->nationality,
                'ethnicity' => $student->ethnicity,
                'religion' => $student->religion,
                'permanent_address' => $student->permanent_address,
                'avatar' => $student->avatar,
            ]
        ]);
    }

    
    public function resetPassword(ResetPasswordRequest $request)
    {
        $reset = DB::table('password_resets')
            ->where('email', $request->email)
            ->where('token', $request->token)
            ->first();

        if (!$reset) {
            return response()->json([
                'message' => 'Token không hợp lệ'
            ], 400);
        }

        $student = Student::where('email', $request->email)->first();

        if (!$student || !$student->account) {
            return response()->json(['message' => 'Không tìm thấy tài khoản'], 404);
        }

        $student->account->update([
            'password' => Hash::make($request->password)
        ]);

        // Xóa token
        DB::table('password_resets')->where('email', $request->email)->delete();

        return response()->json([
            'message' => 'Đổi mật khẩu thành công'
        ]);
    }

    public function sendOtp(SendOtpRequest $request)
    {
        $student = Student::where('email', $request->email)->first();
        $account = $student?->account;

        if (!$account) {
            return response()->json(['message' => 'Không tìm thấy tài khoản'], 404);
        }

        $otp = random_int(100000, 999999);

        $account->otp_code = $otp;
        $account->otp_expire = now()->addMinutes(5);
        $account->save();

        Mail::send([], [], function ($message) use ($request, $otp) {
            $message->to($request->email)
                ->subject('TRƯỜNG ĐH CÔNG NGHỆ SÀI GÒN - ĐỔI MẬT KHẨU')
                ->html("
                <div style='font-family: Arial; max-width:600px; margin:auto; border:1px solid #ddd; padding:20px; background:#f9fafc'>
                    
                    <h2 style='color:#1f2a44;'>TRƯỜNG ĐH CÔNG NGHỆ SÀI GÒN</h2>

                    <p>Bạn vừa yêu cầu đổi mật khẩu.</p>

                    <p>Mã xác minh:</p>

                    <div style='font-size:34px; font-weight:bold; color:#e53935; letter-spacing:6px;
                        background:#fff3f3; padding:10px 16px; border-radius:8px; display:inline-block'>
                        {$otp}
                    </div>

                    <p style='font-size:13px; color:#666'>
                        Mã có hiệu lực trong 5 phút.
                    </p>

                    <hr>

                    <p style='font-size:12px; color:#999'>
                        Đây là email tự động. Vui lòng không trả lời.
                    </p>
                </div>
            ");
        });

        return response()->json(['message' => 'Đã gửi OTP']);
    }

    public function resetWithOtp(ResetPasswordOtpRequest $request)
    {
        $student = Student::where('email', $request->email)->first();
        $account = $student?->account;

        if (!$account) {
            return response()->json(['message' => 'Không tìm thấy tài khoản'], 404);
        }

        if ($account->otp_code != $request->otp) {
            return response()->json(['message' => 'OTP sai'], 400);
        }

        if (now()->gt($account->otp_expire)) {
            return response()->json(['message' => 'OTP hết hạn'], 400);
        }

        $account->password = Hash::make($request->password);
        $account->otp_code = null;
        $account->otp_expire = null;
        $account->save();

        return response()->json(['message' => 'Đổi mật khẩu thành công']);
    }
}
