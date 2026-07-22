<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Middleware kiểm tra role của tài khoản đang đăng nhập (dựa trên $request->user()
// do auth:sanctum cung cấp). Chưa được gắn vào route nào — chuẩn bị sẵn cho các
// nhóm route sẽ được bảo vệ ở bước sau.
class EnsureAccountRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $account = $request->user();

        if (!$account || !in_array($account->role, $roles, true)) {
            return response()->json(['message' => 'Bạn không có quyền truy cập chức năng này.'], 403);
        }

        return $next($request);
    }
}
