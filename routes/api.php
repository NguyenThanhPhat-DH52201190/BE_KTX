<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\RegistrationController;
use App\Http\Controllers\Api\StorageController;

// Tuyến xác thực
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/send-reset-link', [AuthController::class, 'sendResetLink']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);
Route::post('/send-otp', [AuthController::class, 'sendOtp']);
Route::post('/reset-password-otp', [AuthController::class, 'resetWithOtp']);
Route::post('/check-email', [AuthController::class, 'checkEmail']);
Route::post('/check-student-code', [AuthController::class, 'checkStudentCode']);

// Storage routes for Railway volume (must be before any wildcard routes)
Route::get('/storage/debug', [StorageController::class, 'debug']);
Route::get('/storage/{path}', [StorageController::class, 'serveImage'])->where('path', '.*');

// Tuyến đăng ký - phải đặt /me và /history trước /{id}
Route::post('/registration', [RegistrationController::class, 'store']);
Route::get('/registration/me', [RegistrationController::class, 'getMyRegistration']);
Route::get('/registration/history/{email}/{semester}', [RegistrationController::class, 'getRegistrationHistory']);
Route::get('/registration', [RegistrationController::class, 'index']);
Route::get('/registration/{id}', [RegistrationController::class, 'show']);
Route::put('/registration/{id}/approve', [RegistrationController::class, 'approve']);
Route::put('/registration/{id}/reject', [RegistrationController::class, 'reject']);
// Hành động quản trị tùy chọn: phân phòng và chọn giường (frontend cần các tuyến này)
Route::put('/registration/{id}/assign-room', [RegistrationController::class, 'assignRoom']);
Route::put('/registration/select-bed', [RegistrationController::class, 'selectBed']);

// Danh sách phòng dùng cho frontend
Route::get('/rooms', [RegistrationController::class, 'getRooms']);