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
        $account = Account::create([
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => 'student',
            'is_active' => 1,
        ]);

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
                'role' => $account->role
            ]
        ]);
    }

    // ✅ FORGOT PASSWORD
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
        $exists = \App\Models\Student::where('student_code', $request->student_code)->exists();

        return response()->json([
            'exists' => $exists
        ]);
    }

    public function sendResetLink(SendResetLinkRequest $request)
    {
        $account = Account::where('email', $request->email)->first();

        // 🔥 tạo token
        $token = Str::random(60);

        // lưu DB
        DB::table('password_resets')->updateOrInsert(
            ['email' => $request->email],
            [
                'token' => $token,
                'created_at' => now()
            ]
        );

        // 🔥 link reset
        $resetLink = "http://localhost:5173/reset-password?token=$token&email={$request->email}";

        // gửi mail
        Mail::raw("Click link để đổi mật khẩu: $resetLink", function ($message) use ($request) {
            $message->to($request->email)
                ->subject('Reset mật khẩu');
        });

        return response()->json([
            'message' => 'Đã gửi email reset mật khẩu'
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

        // cập nhật password
        Account::where('email', $request->email)->update([
            'password' => Hash::make($request->password)
        ]);

        // xóa token
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
