<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use App\Http\Middleware\EnsureAccountRole;
use App\Http\Middleware\ThrottleAdmissionCandidateVerify;
use App\Http\Middleware\ThrottleDormReservationLookup;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )

    ->withMiddleware(function (Middleware $middleware): void {

        // thêm dòng này
        $middleware->prepend(HandleCors::class);

        $middleware->api(append: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);

        // Đăng ký alias 'role' để dùng ở các route sẽ được bảo vệ sau, ví dụ:
        // ->middleware(['auth:sanctum', 'role:student']). Chưa gắn vào route nào.
        $middleware->alias([
            'role' => EnsureAccountRole::class,
            'admission.verify.limit' => ThrottleAdmissionCandidateVerify::class,
            'reservation.lookup.limit' => ThrottleDormReservationLookup::class,
        ]);

        // App này là pure JSON API, không có route tên 'login' (không có trang login
        // render bằng Blade). Mặc định, middleware auth sẽ cố gắng route('login') khi
        // request không có Accept: application/json, gây lỗi 500 RouteNotFoundException
        // thay vì trả 401 sạch. redirectGuestsTo(null) tắt hẳn hành vi redirect này cho
        // cả Authenticate, AuthenticateSession và AuthenticationException.
        $middleware->redirectGuestsTo(fn () => null);
    })

    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
