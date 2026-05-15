<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
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
        $payload = [
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => 'student',
            'is_active' => 1,
        ];

        if ($request->filled('student_code')) {
            $payload['student_code'] = $request->input('student_code');
        }

        if ($request->filled('full_name')) {
            $payload['full_name'] = $request->input('full_name');
        }

        // Nếu đã có bản ghi sinh viên cùng email thì liên kết vào đó.
        $student = Student::where('email', $request->email)->first();
        if ($student) {
            $payload['student_id'] = $student->id;
            // Nếu payload thiếu student_code/full_name thì thử sao chép từ sinh viên (nếu có).
            if (empty($payload['student_code']) && isset($student->student_code)) {
                $payload['student_code'] = $student->student_code;
            }
            if (empty($payload['full_name']) && isset($student->full_name)) {
                $payload['full_name'] = $student->full_name;
            }
        }

        $account = Account::create($payload);

        return response()->json([
            'message' => 'Đăng ký thành công',
            'account' => $account
        ], 201);
    }

    public function login(LoginRequest $request)
    {
        $account = Account::where('email', $request->email)->first();

        if (!$account || !Hash::check($request->password, $account->password)) {
            return response()->json([
                'message' => 'Sai email hoặc mật khẩu'
            ], 401);
        }

        if (!$account->is_active) {
            return response()->json([
                'message' => 'Tài khoản bị khóa'
            ], 403);
        }

        $token = $account->createToken('auth_token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $account->id,
                'email' => $account->email,
                'role' => $account->role,
                'student_id' => $account->student_id,
                // Trả về cả biến thể snake_case và camelCase để tương thích với frontend.
                'student_code' => $account->student_code,
                'studentCode' => $account->student_code,
                'full_name' => $account->full_name,
                'fullName' => $account->full_name,
            ]
        ]);
    }

    // Quên mật khẩu
    public function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $status = Password::sendResetLink(
            $request->only('email')
        );

        return response()->json([
            'message' => __($status)
        ]);
    }

    public function checkEmail(CheckEmailRequest $request)
    {
        $exists = Account::where('email', $request->email)->exists();

        return response()->json([
            'exists' => $exists
        ]);
    }

    public function checkStudentCode(CheckStudentCodeRequest $request)
    {
        // student_code hiện được lưu trong bảng accounts
        $exists = \App\Models\Account::where('student_code', $request->student_code)->exists();

        return response()->json([
            'exists' => $exists
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

        // Cập nhật mật khẩu
        Account::where('email', $request->email)->update([
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
        $account = Account::where('email', $request->email)->first();

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
        $account = Account::where('email', $request->email)->first();

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
