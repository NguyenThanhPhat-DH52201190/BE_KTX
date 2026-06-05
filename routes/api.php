<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\RegistrationController;
use App\Http\Controllers\Api\StorageController;
use App\Http\Controllers\Api\BuildingController;
use App\Http\Controllers\Api\RoomController;

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
Route::put('/registration/{id}/approve-bed', [RegistrationController::class, 'approveBed']);
Route::put('/registration/{id}/reject-bed', [RegistrationController::class, 'rejectBed']);
Route::put('/registration/request-checkout', [RegistrationController::class, 'requestCheckout']);
Route::put('/registration/{id}/confirm-checkout', [RegistrationController::class, 'confirmCheckout']);
Route::put('/registration/{id}/force-checkout', [RegistrationController::class, 'forceCheckout']);

// Danh sách phòng dùng cho frontend
Route::get('/rooms', [\App\Http\Controllers\Api\RoomController::class, 'index']);
Route::post('/rooms', [\App\Http\Controllers\Api\RoomController::class, 'store']);
Route::put('/rooms/{roomId}', [\App\Http\Controllers\Api\RoomController::class, 'update']);
Route::delete('/rooms/{roomId}', [\App\Http\Controllers\Api\RoomController::class, 'destroy']);
Route::put('/rooms/{roomId}/beds/{bedId}', [\App\Http\Controllers\Api\RoomController::class, 'updateBed']);

// Quản lý tòa
Route::get('/buildings', [\App\Http\Controllers\Api\BuildingController::class, 'index']);
Route::post('/buildings', [\App\Http\Controllers\Api\BuildingController::class, 'store']);
Route::get('/buildings/{buildingCode}', [\App\Http\Controllers\Api\BuildingController::class, 'show']);
Route::put('/buildings/{buildingCode}', [\App\Http\Controllers\Api\BuildingController::class, 'update']);
Route::delete('/buildings/{buildingCode}', [\App\Http\Controllers\Api\BuildingController::class, 'destroy']);
Route::post('/buildings/{buildingCode}/floors', [\App\Http\Controllers\Api\BuildingController::class, 'storeFloor']);
Route::put('/buildings/{buildingCode}/floors/{floorNumber}', [\App\Http\Controllers\Api\BuildingController::class, 'updateFloor']);
Route::delete('/buildings/{buildingCode}/floors/{floorNumber}', [\App\Http\Controllers\Api\BuildingController::class, 'destroyFloor']);
