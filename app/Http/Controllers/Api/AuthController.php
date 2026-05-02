<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\Student;
use App\Models\Account;
use App\Models\User;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $request->validate([
            'email' => 'required|email|unique:accounts,email',
            'password' => 'required|min:6',
        ]);

        $account = Account::create([
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => 'student',
            'is_active' => 1,
        ]);

        return response()->json([
            'message' => 'Đăng ký thành công',
            'account' => $account
        ]);
    }

    // ✅ LOGIN
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

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

        // 🔥 tạo token
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
   
    public function checkEmail(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $exists = User::where('email', $request->email)->exists();

        return response()->json([
            'exists' => $exists
        ]);
    }

    public function checkStudentCode(Request $request)
    {
        $request->validate([
            'student_code' => 'required'
        ]);

        $exists = \App\Models\Student::where('student_code', $request->student_code)->exists();

        return response()->json([
            'exists' => $exists
        ]);
    }

    public function sendResetLink(Request $request){
        $request->validate([
            'email' => 'required|email'
        ]);

        $account = Account::where('email', $request->email)->first();

        if (!$account) {
            return response()->json([
                'message' => 'Email không tồn tại'
            ], 404);
        }

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
    public function resetPassword(Request $request)
{
    $request->validate([
        'email' => 'required|email',
        'token' => 'required',
        'password' => 'required|min:6',
    ]);

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
    
}
