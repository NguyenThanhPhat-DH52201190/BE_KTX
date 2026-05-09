<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\RegistrationController;

// Auth Routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/send-reset-link', [AuthController::class, 'sendResetLink']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);
Route::post('/send-otp', [AuthController::class, 'sendOtp']);
Route::post('/reset-password-otp', [AuthController::class, 'resetWithOtp']);
Route::post('/check-email', [AuthController::class, 'checkEmail']);
Route::post('/check-student-code', [AuthController::class, 'checkStudentCode']);

// Registration Routes - phải đặt /me trước /{id}
Route::post('/registration', [RegistrationController::class, 'store']);
Route::get('/registration/me', [RegistrationController::class, 'getMyRegistration']);
Route::get('/registration', [RegistrationController::class, 'index']);
Route::get('/registration/{id}', [RegistrationController::class, 'show']);
Route::put('/registration/{id}/approve', [RegistrationController::class, 'approve']);
Route::put('/registration/{id}/reject', [RegistrationController::class, 'reject']);
// Optional admin actions: assign room and select bed (frontend expects these)
Route::put('/registration/{id}/assign-room', [RegistrationController::class, 'assignRoom']);
Route::put('/registration/select-bed', [RegistrationController::class, 'selectBed']);

// Rooms listing used by frontend
Route::get('/rooms', [RegistrationController::class, 'getRooms']);