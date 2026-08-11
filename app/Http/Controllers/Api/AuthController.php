<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
use Laravel\Sanctum\PersonalAccessToken;


class AuthController extends Controller
{
    // Đăng xuất: thu hồi toàn bộ token Sanctum của tài khoản đang giữ token này.
    // Xác định tài khoản thủ công qua bearer token (chưa gắn middleware auth:sanctum
    // cho route này — nằm ngoài phạm vi hạ tầng của bước hiện tại).
    public function logout(Request $request)
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json(['message' => 'Không tìm thấy token.'], 401);
        }

        $accessToken = PersonalAccessToken::findToken($token);

        if (!$accessToken) {
            return response()->json(['message' => 'Token không hợp lệ.'], 401);
        }

        $accessToken->tokenable->tokens()->delete();

        return response()->json(['message' => 'Đăng xuất thành công.']);
    }

    // Đổi mật khẩu cho tài khoản đang đăng nhập (student hoặc admin).
    // Route được bảo vệ bằng auth:sanctum nên $request->user() là Account thật sự
    // đang giữ token, không nhận email/id từ client.
    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required|string',
            // Đồng bộ rule với RegisterRequest/ResetPasswordRequest (min:6 + confirmed).
            'new_password' => 'required|min:6|confirmed',
        ]);

        $account = $request->user();

        if (!Hash::check($request->current_password, $account->password)) {
            return response()->json(['message' => 'Mật khẩu hiện tại không đúng.'], 422);
        }

        if (Hash::check($request->new_password, $account->password)) {
            return response()->json(['message' => 'Mật khẩu mới không được trùng mật khẩu hiện tại.'], 422);
        }

        $account->password = Hash::make($request->new_password);
        $account->save();

        // Thu hồi toàn bộ token hiện có, bắt buộc đăng nhập lại ở mọi phiên.
        $account->tokens()->delete();

        return response()->json(['message' => 'Đổi mật khẩu thành công.']);
    }

    public function register(RegisterRequest $request)
    {
        // Lấy sinh viên theo MSSV (RegisterRequest đã xác thực tồn tại trong students)
        $student = Student::where('student_code', $request->student_code)->first();

        if (!$student) {
            return response()->json(['message' => 'MSSV không tồn tại'], 400);
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
        $student = null;
        $account = null;

        // Ưu tiên luồng sinh viên hiện có để giữ tương thích dữ liệu hiện tại.
        if ($request->filled('student_code')) {
            $student = Student::where('student_code', $request->student_code)->first();
            $account = $student?->account;
        } else {
            // Email chỉ dùng cho tài khoản quản trị. Sinh viên đăng nhập bằng MSSV.
            if (!$account && Schema::hasColumn('accounts', 'email')) {
                $account = Account::where('email', $request->email)->where('role', 'admin')->first();
            }

            if (!$account) {
                $adminLoginEmail = config('auth.admin_login_email');

                if (!empty($adminLoginEmail) && strcasecmp((string) $request->email, (string) $adminLoginEmail) === 0) {
                    $account = Account::where('role', 'admin')->orderBy('id')->first();
                }
            }
        }

        if (!$student && $account?->student_id) {
            $student = Student::find($account->student_id);
        }

        if (!$account || !Hash::check($request->password, $account->password)) {
            return response()->json(['message' => 'Sai thông tin đăng nhập hoặc mật khẩu'], 401);
        }

        $token = $account->createToken('auth_token')->plainTextToken;
        $isAdmin = $account->role === 'admin';
        $accountEmail = Schema::hasColumn('accounts', 'email') ? ($account->email ?? null) : null;
        $adminEmail = $isAdmin
            ? ($accountEmail ?? config('auth.admin_login_email') ?? $request->email)
            : null;

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $account->id,
                'email' => $adminEmail ?? $student?->email ?? ($request->email ?? null),
                'role' => $account->role,
                'student_id' => $isAdmin ? null : $account->student_id,
                'student_code' => $isAdmin ? null : $student?->student_code,
                'studentCode' => $isAdmin ? null : $student?->student_code,
                'full_name' => $isAdmin ? null : $student?->full_name,
                'fullName' => $isAdmin ? null : $student?->full_name,
            ]
        ]);
    }

    // Trả về danh tính/vai trò thật của tài khoản đang giữ token, đọc thẳng từ CSDL
    // qua $request->user() (route đã bảo vệ bằng auth:sanctum) — dùng cùng cấu trúc
    // với response của login() để frontend đối chiếu lại role đang lưu ở localStorage,
    // tránh bị qua mặt nếu ai đó tự sửa tay giá trị role trong localStorage.
    public function me(Request $request)
    {
        $account = $request->user();
        $isAdmin = $account->role === 'admin';
        $student = $isAdmin ? null : ($account->student_id ? Student::find($account->student_id) : null);
        $accountEmail = Schema::hasColumn('accounts', 'email') ? ($account->email ?? null) : null;
        $adminEmail = $isAdmin ? ($accountEmail ?? config('auth.admin_login_email')) : null;

        return response()->json([
            'id' => $account->id,
            'email' => $adminEmail ?? $student?->email ?? null,
            'role' => $account->role,
            'student_id' => $isAdmin ? null : $account->student_id,
            'student_code' => $isAdmin ? null : $student?->student_code,
            'studentCode' => $isAdmin ? null : $student?->student_code,
            'full_name' => $isAdmin ? null : $student?->full_name,
            'fullName' => $isAdmin ? null : $student?->full_name,
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
        // Route công khai (chưa đăng nhập) — chỉ trả về việc MSSV có tồn tại hay không.
        // Không trả thông tin sinh viên (CCCD, SĐT, ngày sinh, địa chỉ...) ở đây vì bất kỳ
        // ai biết/đoán được MSSV cũng gọi được route này. Client đã đăng nhập cần dữ liệu
        // đầy đủ của chính mình thì gọi GET /student/profile (xác thực qua token).
        $exists = Student::where('student_code', $request->student_code)->exists();

        return response()->json(['exists' => $exists]);
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

        try {
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
        } catch (\Throwable $e) {
            Log::error('Gửi email thông báo thất bại', [
                'type'       => 'otp_password_change',
                'student_id' => $student->id,
                'email'      => $request->email,
                'error'      => $e->getMessage(),
            ]);
            throw $e;
        }

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

        // Thu hồi toàn bộ token hiện có, giống changePassword() — quên mật khẩu
        // thường gắn với nghi ngờ lộ tài khoản nên cũng phải đăng xuất mọi phiên cũ.
        $account->tokens()->delete();

        return response()->json(['message' => 'Đổi mật khẩu thành công']);
    }
}
