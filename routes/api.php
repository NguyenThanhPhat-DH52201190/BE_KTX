<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProvinceController;
use App\Http\Controllers\Api\RegistrationController;
use App\Http\Controllers\Api\StorageController;
use App\Http\Controllers\Api\BuildingController;
use App\Http\Controllers\Api\RoomController;
use App\Http\Controllers\Api\ViolationController;
use App\Http\Controllers\Api\ViolationTypeController;
use App\Http\Controllers\Api\RoomFeeBillController;
use App\Http\Controllers\Api\ElectricityController;
use App\Http\Controllers\Api\PaymentSettingController;
use App\Http\Controllers\Api\StudentPaymentController;
use App\Http\Controllers\Api\VnpayPaymentController;
use App\Http\Controllers\Api\MaintenanceController;

// Tuyến xác thực
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/send-reset-link', [AuthController::class, 'sendResetLink']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);
Route::post('/send-otp', [AuthController::class, 'sendOtp']);
Route::post('/reset-password-otp', [AuthController::class, 'resetWithOtp']);
Route::post('/check-email', [AuthController::class, 'checkEmail']);
Route::post('/check-student-code', [AuthController::class, 'checkStudentCode']);

// Danh mục tỉnh/thành (dropdown form đăng ký)
Route::get('/provinces', [ProvinceController::class, 'index']);

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
Route::get('/rooms/{roomId}/beds', [\App\Http\Controllers\Api\RoomController::class, 'beds']);
Route::post('/rooms', [\App\Http\Controllers\Api\RoomController::class, 'store']);
Route::put('/rooms/{roomId}', [\App\Http\Controllers\Api\RoomController::class, 'update']);
Route::delete('/rooms/{roomId}', [\App\Http\Controllers\Api\RoomController::class, 'destroy']);
Route::put('/rooms/{roomId}/beds/{bedId}/transfer', [\App\Http\Controllers\Api\RoomController::class, 'transferBedOccupancy']);
Route::put('/rooms/{roomId}/beds/{bedId}', [\App\Http\Controllers\Api\RoomController::class, 'updateBed']);

// Bảo trì giường/phòng dùng wizard, không thay đổi API phòng cũ.
Route::get('/maintenance/rooms/{roomId}/beds/{bedId}/plan', [MaintenanceController::class, 'bedPlan']);
Route::post('/maintenance/rooms/{roomId}/beds/{bedId}/start', [MaintenanceController::class, 'startBedMaintenance']);
Route::post('/maintenance/rooms/{roomId}/beds/{bedId}/complete', [MaintenanceController::class, 'completeBedMaintenance']);
Route::get('/maintenance/rooms/{roomId}/plan', [MaintenanceController::class, 'roomPlan']);
Route::post('/maintenance/rooms/{roomId}/start', [MaintenanceController::class, 'startRoomMaintenance']);
Route::post('/maintenance/rooms/{roomId}/complete-student/{occupancyId}', [MaintenanceController::class, 'completeRoomMaintenanceStudent']);
Route::post('/maintenance/rooms/{roomId}/complete', [MaintenanceController::class, 'completeRoomMaintenance']);

// Quản lý tòa
Route::get('/buildings', [\App\Http\Controllers\Api\BuildingController::class, 'index']);
Route::post('/buildings', [\App\Http\Controllers\Api\BuildingController::class, 'store']);
Route::get('/buildings/{buildingCode}', [\App\Http\Controllers\Api\BuildingController::class, 'show']);
Route::put('/buildings/{buildingCode}', [\App\Http\Controllers\Api\BuildingController::class, 'update']);
Route::delete('/buildings/{buildingCode}', [\App\Http\Controllers\Api\BuildingController::class, 'destroy']);
Route::post('/buildings/{buildingCode}/floors', [\App\Http\Controllers\Api\BuildingController::class, 'storeFloor']);
Route::put('/buildings/{buildingCode}/floors/{floorNumber}', [\App\Http\Controllers\Api\BuildingController::class, 'updateFloor']);
Route::delete('/buildings/{buildingCode}/floors/{floorNumber}', [\App\Http\Controllers\Api\BuildingController::class, 'destroyFloor']);

// Quản lý loại vi phạm
Route::get('/violation-types', [ViolationTypeController::class, 'index']);
Route::post('/violation-types', [ViolationTypeController::class, 'store']);
Route::put('/violation-types/{id}', [ViolationTypeController::class, 'update']);
Route::delete('/violation-types/{id}', [ViolationTypeController::class, 'destroy']);

// Quản lý vi phạm
Route::get('/violations', [ViolationController::class, 'index']);
Route::post('/violations', [ViolationController::class, 'store']);
Route::put('/violations/{id}', [ViolationController::class, 'update']);
Route::put('/violations/{id}/process', [ViolationController::class, 'process']);
Route::delete('/violations/{id}', [ViolationController::class, 'destroy']);

// Quản lý thanh toán
Route::get('/payment-settings', [PaymentSettingController::class, 'show']);
Route::put('/payment-settings', [PaymentSettingController::class, 'update']);

Route::get('/room-fee-bills', [RoomFeeBillController::class, 'index']);
Route::post('/room-fee-bills/generate', [RoomFeeBillController::class, 'generate']);
Route::put('/room-fee-bills/{id}/confirm-payment', [RoomFeeBillController::class, 'confirmPayment']);
Route::put('/room-fee-bills/{id}/status', [RoomFeeBillController::class, 'updateStatus']);

Route::get('/electricity-records', [ElectricityController::class, 'records']);
Route::post('/electricity-records/generate', [ElectricityController::class, 'generate']);
Route::get('/electricity-bills', [ElectricityController::class, 'bills']);
Route::put('/electricity-bills/{id}/confirm-payment', [ElectricityController::class, 'confirmPayment']);
Route::put('/electricity-bills/{id}/status', [ElectricityController::class, 'updateStatus']);

Route::get('/student/payments', [StudentPaymentController::class, 'myBills']);
Route::post('/payments/vnpay/create', [VnpayPaymentController::class, 'create']);
Route::post('/payments/vnpay/verify', [VnpayPaymentController::class, 'verify']);
Route::get('/payments/vnpay/return', [VnpayPaymentController::class, 'handleReturn']);
