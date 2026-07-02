<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\StaticPageController;
use App\Http\Controllers\Api\ProvinceController;
use App\Http\Controllers\Api\RegistrationController;
use App\Http\Controllers\Api\StorageController;
use App\Http\Controllers\Api\BuildingController;
use App\Http\Controllers\Api\RoomController;
use App\Http\Controllers\Api\ViolationController;
use App\Http\Controllers\Api\ViolationTypeController;
use App\Http\Controllers\Api\RoomFeeBillController;
use App\Http\Controllers\Api\StudentPaymentPlanController;
use App\Http\Controllers\Api\ElectricityController;
use App\Http\Controllers\Api\PaymentSettingController;
use App\Http\Controllers\Api\StudentPaymentController;
use App\Http\Controllers\Api\VnpayPaymentController;
use App\Http\Controllers\Api\MaintenanceController;
use App\Http\Controllers\Api\PriorityCriteriaController;
use App\Http\Controllers\Api\RegistrationPeriodController;
use App\Http\Controllers\Api\StudentPriorityController;
use App\Http\Controllers\Api\AutoRoomAssignmentController;
use App\Http\Controllers\Api\StudentSupportRequestController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\AdminNotificationController;
use App\Http\Controllers\Api\OccupancyController;
use App\Http\Controllers\Api\StudentSearchController;
use App\Http\Controllers\Api\OccupancyExtensionController;
use App\Http\Controllers\Api\OccupancyPeriodController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\StudentDashboardController;
use App\Http\Controllers\Api\AdmissionCandidateController;
use App\Http\Controllers\Api\DormReservationController;
use App\Http\Controllers\Api\ReservationPriorityController;
use Illuminate\Support\Facades\Artisan;

// Trang tĩnh (public)
Route::get('/pages/{slug}', [StaticPageController::class, 'show']);

// Quản lý nội dung trang tĩnh (admin) — POST để hỗ trợ multipart/form-data (upload ảnh)
Route::post('/admin/pages/{slug}', [StaticPageController::class, 'update']);

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

// Tiêu chí ưu tiên (dùng cho form đăng ký)
Route::get('/priority-criteria', [PriorityCriteriaController::class, 'index']);

// Quản lý đợt đăng ký
Route::get('/registration-periods', [RegistrationPeriodController::class, 'index']);
Route::post('/registration-periods', [RegistrationPeriodController::class, 'store']);
Route::get('/registration-periods/{id}', [RegistrationPeriodController::class, 'show']);
Route::put('/registration-periods/{id}', [RegistrationPeriodController::class, 'update']);
Route::delete('/registration-periods/{id}', [RegistrationPeriodController::class, 'destroy']);
Route::post('/registration-periods/{id}/process', [RegistrationPeriodController::class, 'process']);

// Storage routes for Railway volume (must be before any wildcard routes)
Route::get('/storage/debug', [StorageController::class, 'debug']);
Route::get('/storage/{path}', [StorageController::class, 'serveImage'])->where('path', '.*');

// Tuyến đăng ký - phải đặt /me và /history và /eligibility trước /{id}
Route::get('/registration/eligibility', [RegistrationController::class, 'eligibility']);
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
Route::patch('/admin/registrations/{id}/auto-decision', [RegistrationController::class, 'patchAutoDecision']);
Route::post('/admin/registrations/{id}/confirm', [RegistrationController::class, 'confirmSingle']);
Route::post('/admin/registration-periods/{id}/confirm-batch', [RegistrationController::class, 'confirmBatch']);

// Xác minh tiêu chí ưu tiên
Route::get('/admin/student-priority', [StudentPriorityController::class, 'index']);
Route::patch('/admin/student-priority/{id}/verify', [StudentPriorityController::class, 'verify']);

// Phân phòng tự động
Route::post('/admin/rooms/auto-assign', [AutoRoomAssignmentController::class, 'autoAssign']);
Route::post('/admin/rooms/confirm-proposals', [AutoRoomAssignmentController::class, 'confirmProposals']);

// Dashboard admin
Route::get('/admin/dashboard', [DashboardController::class, 'index']);
Route::get('/admin/dashboard/finance', [DashboardController::class, 'finance']);
Route::get('/admin/dashboard/revenue-trend', [DashboardController::class, 'revenueTrend']);

// Route test thủ công (chỉ dùng local/dev)
Route::get('/admin/run-period-status-update', function () {
    Artisan::call('periods:update-status');
    return response()->json(['output' => Artisan::output()]);
});

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
Route::put('/room-fee-bills/{id}/exempt', [RoomFeeBillController::class, 'exempt']);
Route::put('/room-fee-bills/{id}/apply-discount', [RoomFeeBillController::class, 'applyOneTimeDiscount']);

Route::get('/admin/students/{studentId}/payment-plans', [StudentPaymentPlanController::class, 'index']);
Route::post('/admin/students/{studentId}/payment-plans', [StudentPaymentPlanController::class, 'store']);
Route::put('/admin/payment-plans/{id}/deactivate', [StudentPaymentPlanController::class, 'deactivate']);

Route::get('/electricity-records', [ElectricityController::class, 'records']);
Route::post('/electricity-records/generate', [ElectricityController::class, 'generate']);
Route::get('/electricity-bills', [ElectricityController::class, 'bills']);
Route::put('/electricity-bills/{id}/confirm-payment', [ElectricityController::class, 'confirmPayment']);
Route::put('/electricity-bills/{id}/status', [ElectricityController::class, 'updateStatus']);

Route::get('/student/dashboard', [StudentDashboardController::class, 'index']);
Route::get('/student/payments', [StudentPaymentController::class, 'myBills']);
Route::post('/student/payments/room-fee-bills/{id}/confirm-free', [StudentPaymentController::class, 'confirmFree']);
Route::get('/student/support-requests', [StudentSupportRequestController::class, 'studentIndex']);
Route::get('/student/support-requests/roommate-target', [StudentSupportRequestController::class, 'roommateTarget']);
Route::get('/student/support-requests/{id}', [StudentSupportRequestController::class, 'studentShow']);
Route::post('/student/support-requests', [StudentSupportRequestController::class, 'store']);

Route::get('/admin/support-requests', [StudentSupportRequestController::class, 'adminIndex']);
Route::get('/admin/support-requests/{id}', [StudentSupportRequestController::class, 'adminShow']);
Route::put('/admin/support-requests/{id}/status', [StudentSupportRequestController::class, 'updateStatus']);
Route::put('/admin/support-requests/{id}/process', [StudentSupportRequestController::class, 'process']);
Route::put('/admin/support-requests/{id}/approve', [StudentSupportRequestController::class, 'approve']);
Route::put('/admin/support-requests/{id}/reject', [StudentSupportRequestController::class, 'reject']);
Route::put('/admin/support-requests/{id}/complete', [StudentSupportRequestController::class, 'complete']);
// Chi tiết lưu trú (admin)
Route::get('/admin/occupancies/{id}/detail', [OccupancyController::class, 'detail'])->whereNumber('id');

// Tìm kiếm sinh viên (autocomplete)
Route::get('/admin/students/search', [StudentSearchController::class, 'search']);

// Đợt gia hạn lưu trú
Route::get('/occupancy-periods', [OccupancyPeriodController::class, 'index']);
Route::post('/occupancy-periods', [OccupancyPeriodController::class, 'store']);
Route::get('/occupancy-periods/{id}', [OccupancyPeriodController::class, 'show']);
Route::put('/occupancy-periods/{id}', [OccupancyPeriodController::class, 'update']);
Route::delete('/occupancy-periods/{id}', [OccupancyPeriodController::class, 'destroy']);
Route::put('/occupancy-periods/{id}/open', [OccupancyPeriodController::class, 'open']);
Route::put('/occupancy-periods/{id}/close', [OccupancyPeriodController::class, 'close']);

// Thông báo sinh viên
Route::get('/student/notifications/unread-count', [NotificationController::class, 'unreadCount']);
Route::get('/student/notifications', [NotificationController::class, 'index']);
Route::put('/student/notifications/read-all', [NotificationController::class, 'markAllRead']);
Route::put('/student/notifications/{id}/read', [NotificationController::class, 'markRead'])->whereNumber('id');

// Thông báo admin
Route::get('/admin/notifications/unread-count', [AdminNotificationController::class, 'unreadCount']);
Route::get('/admin/notifications', [AdminNotificationController::class, 'index']);
Route::put('/admin/notifications/read-all', [AdminNotificationController::class, 'markAllRead']);
Route::put('/admin/notifications/{id}/read', [AdminNotificationController::class, 'markRead'])->whereNumber('id');

// Yêu cầu gia hạn lưu trú
Route::get('/student/extensions/eligibility', [OccupancyExtensionController::class, 'eligibility']);
Route::get('/student/extensions', [OccupancyExtensionController::class, 'studentIndex']);
Route::post('/student/extensions', [OccupancyExtensionController::class, 'store']);

Route::get('/admin/extensions/stats', [OccupancyExtensionController::class, 'stats']);
Route::get('/admin/extensions', [OccupancyExtensionController::class, 'adminIndex']);
Route::get('/admin/extensions/{id}', [OccupancyExtensionController::class, 'adminShow']);
Route::put('/admin/extensions/{id}/approve', [OccupancyExtensionController::class, 'approve']);
Route::put('/admin/extensions/{id}/reject', [OccupancyExtensionController::class, 'reject']);

Route::post('/payments/vnpay/create', [VnpayPaymentController::class, 'create']);
Route::post('/payments/vnpay/verify', [VnpayPaymentController::class, 'verify']);
Route::get('/payments/vnpay/return', [VnpayPaymentController::class, 'handleReturn']);

// =====================================================================
// Tân sinh viên — hồ sơ trúng tuyển & giữ chỗ KTX
// =====================================================================

// Public: xác minh thí sinh trúng tuyển
Route::post('/admission-candidates/verify', [DormReservationController::class, 'verify']);

// Public: tạo hồ sơ giữ chỗ
Route::post('/dorm-reservations', [DormReservationController::class, 'store']);
Route::post('/dorm-reservations/{id}/upload-document', [DormReservationController::class, 'uploadDocument'])->whereNumber('id');

// Public: khai báo + xoá tiêu chí ưu tiên, upload/xoá minh chứng
Route::post('/dorm-reservations/{id}/priorities', [ReservationPriorityController::class, 'store'])->whereNumber('id');
Route::delete('/reservation-priorities/{id}', [ReservationPriorityController::class, 'destroy'])->whereNumber('id');
Route::post('/reservation-priorities/{id}/evidences', [ReservationPriorityController::class, 'storeEvidence'])->whereNumber('id');
Route::delete('/reservation-priority-evidences/{id}', [ReservationPriorityController::class, 'destroyEvidence'])->whereNumber('id');

// Admin: quản lý thí sinh trúng tuyển
Route::get('/admin/admission-candidates', [AdmissionCandidateController::class, 'index']);
Route::post('/admin/admission-candidates', [AdmissionCandidateController::class, 'store']);
Route::get('/admin/admission-candidates/import-template', [AdmissionCandidateController::class, 'importTemplate']);
Route::post('/admin/admission-candidates/bulk-enroll', [AdmissionCandidateController::class, 'bulkEnroll']);
Route::get('/admin/admission-candidates/{id}', [AdmissionCandidateController::class, 'show'])->whereNumber('id');
Route::put('/admin/admission-candidates/{id}', [AdmissionCandidateController::class, 'update'])->whereNumber('id');
Route::delete('/admin/admission-candidates/{id}', [AdmissionCandidateController::class, 'destroy'])->whereNumber('id');
Route::post('/admin/admission-candidates/{id}/enroll', [AdmissionCandidateController::class, 'enroll'])->whereNumber('id');

// Admin: xác minh tiêu chí ưu tiên cho hồ sơ giữ chỗ
Route::get('/admin/reservation-priorities', [ReservationPriorityController::class, 'index']);
Route::patch('/admin/reservation-priorities/{id}/verify', [ReservationPriorityController::class, 'verify'])->whereNumber('id');
Route::patch('/admin/reservation-priorities/{id}/reject', [ReservationPriorityController::class, 'reject'])->whereNumber('id');

// Admin: xếp hạng + batch convert (phải đặt trước {id} routes để tránh conflict)
Route::post('/admin/dorm-reservations/rank', [DormReservationController::class, 'rankReservations']);
Route::post('/admin/dorm-reservations/batch-convert', [DormReservationController::class, 'batchConvert']);

// Admin: quản lý hồ sơ giữ chỗ KTX
Route::get('/admin/dorm-reservations', [DormReservationController::class, 'index']);
Route::get('/admin/dorm-reservations/{id}', [DormReservationController::class, 'show'])->whereNumber('id');
Route::put('/admin/dorm-reservations/{id}/approve', [DormReservationController::class, 'approve'])->whereNumber('id');
Route::put('/admin/dorm-reservations/{id}/reject', [DormReservationController::class, 'reject'])->whereNumber('id');
Route::put('/admin/dorm-reservations/{id}/waitlist', [DormReservationController::class, 'waitlist'])->whereNumber('id');
Route::put('/admin/dorm-reservations/{id}/cancel', [DormReservationController::class, 'cancel'])->whereNumber('id');
Route::put('/admin/dorm-reservations/{id}/note', [DormReservationController::class, 'note'])->whereNumber('id');
Route::post('/admin/dorm-reservations/{id}/convert-to-registration', [DormReservationController::class, 'convertToRegistration'])->whereNumber('id');
