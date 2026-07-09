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
use App\Http\Controllers\Api\StudentRoomChangeHistoryController;
use App\Http\Controllers\Api\StudentSearchController;
use App\Http\Controllers\Api\StudentProfileController;
use App\Http\Controllers\Api\StudentFaceSearchController;
use App\Http\Controllers\Api\OccupancyExtensionController;
use App\Http\Controllers\Api\OccupancyPeriodController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\StudentDashboardController;
use App\Http\Controllers\Api\AdmissionCandidateController;
use App\Http\Controllers\Api\DormReservationController;
use App\Http\Controllers\Api\ReservationPriorityController;
use App\Http\Controllers\Api\SystemAnnouncementController;

// Route công khai (không yêu cầu đăng nhập) — giới hạn tốc độ chung (60 req/phút/IP) để
// chặn brute-force/abuse hàng loạt, vì các route này không có auth:sanctum bảo vệ.
Route::middleware('throttle:60,1')->group(function () {
    // Trang tĩnh (public)
    Route::get('/pages/{slug}', [StaticPageController::class, 'show']);

    // Tuyến xác thực
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/send-reset-link', [AuthController::class, 'sendResetLink']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
    Route::post('/send-otp', [AuthController::class, 'sendOtp']);
    Route::post('/reset-password-otp', [AuthController::class, 'resetWithOtp']);
    Route::post('/check-email', [AuthController::class, 'checkEmail']);
    Route::post('/check-student-code', [AuthController::class, 'checkStudentCode']);
});

// Route đã bảo vệ bằng auth:sanctum (Bước 2B + nhóm /student/* Bước 2C) — các route
// còn lại trong file này (admin/*, và các route không tiền tố) vẫn chưa được bảo vệ,
// sẽ làm dần ở bước sau.
Route::middleware('auth:sanctum')->group(function () {
    // Đổi mật khẩu: dùng chung cho mọi loại tài khoản (student/admin).
    Route::post('/change-password', [AuthController::class, 'changePassword']);

    Route::middleware('role:student')->group(function () {
        Route::get('/student/profile', [StudentProfileController::class, 'show']);
        Route::get('/student/dashboard', [StudentDashboardController::class, 'index']);
        Route::get('/student/payments', [StudentPaymentController::class, 'myBills']);
        Route::post('/student/payments/room-fee-bills/{id}/confirm-free', [StudentPaymentController::class, 'confirmFree']);
        Route::get('/student/support-requests', [StudentSupportRequestController::class, 'studentIndex']);
        Route::get('/student/support-requests/roommate-target', [StudentSupportRequestController::class, 'roommateTarget']);
        Route::get('/student/support-requests/{id}', [StudentSupportRequestController::class, 'studentShow']);
        Route::post('/student/support-requests', [StudentSupportRequestController::class, 'store']);
        Route::get('/student/notifications/unread-count', [NotificationController::class, 'unreadCount']);
        Route::get('/student/notifications/system-announcements/{id}', [NotificationController::class, 'systemAnnouncement'])->whereNumber('id');
        Route::get('/student/notifications', [NotificationController::class, 'index']);
        Route::put('/student/notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::put('/student/notifications/{id}/read', [NotificationController::class, 'markRead'])->whereNumber('id');
        Route::get('/student/extensions/eligibility', [OccupancyExtensionController::class, 'eligibility']);
        Route::get('/student/extensions', [OccupancyExtensionController::class, 'studentIndex']);
        Route::post('/student/extensions', [OccupancyExtensionController::class, 'store']);
        Route::get('/student/room-change-history', [StudentRoomChangeHistoryController::class, 'index']);

        // VNPay: khởi tạo & xác minh thanh toán do sinh viên tự thực hiện cho hóa đơn của mình.
        Route::post('/payments/vnpay/create', [VnpayPaymentController::class, 'create']);
        Route::post('/payments/vnpay/verify', [VnpayPaymentController::class, 'verify']);

        // Đăng ký nội trú — hành động tự phục vụ của sinh viên (Bước 2F / Lượt A)
        Route::get('/registration/eligibility', [RegistrationController::class, 'eligibility']);
        Route::post('/registration', [RegistrationController::class, 'store']);
        Route::get('/registration/me', [RegistrationController::class, 'getMyRegistration']);
        Route::get('/registration/history/{email}/{semester?}', [RegistrationController::class, 'getRegistrationHistory']);
        Route::put('/registration/select-bed', [RegistrationController::class, 'selectBed']);
        Route::put('/registration/request-checkout', [RegistrationController::class, 'requestCheckout']);
        Route::put('/registration/cancel-checkout', [RegistrationController::class, 'cancelCheckout']);
    });

    Route::middleware('role:admin')->group(function () {
        // Quản lý thanh toán (Bước 2D)
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

        // Nội dung tĩnh (Bước 2E)
        Route::post('/admin/pages/{slug}', [StaticPageController::class, 'update']);
        Route::get('/admin/content/announcements/stats', [SystemAnnouncementController::class, 'stats']);
        Route::get('/admin/content/announcements/target-options', [SystemAnnouncementController::class, 'targetOptions']);
        Route::get('/admin/content/announcements', [SystemAnnouncementController::class, 'index']);
        Route::post('/admin/content/announcements', [SystemAnnouncementController::class, 'store']);
        Route::get('/admin/content/announcements/{id}', [SystemAnnouncementController::class, 'show'])->whereNumber('id');
        Route::put('/admin/content/announcements/{id}', [SystemAnnouncementController::class, 'update'])->whereNumber('id');
        Route::post('/admin/content/announcements/{id}/send', [SystemAnnouncementController::class, 'send'])->whereNumber('id');
        Route::post('/admin/content/announcements/{id}/unschedule', [SystemAnnouncementController::class, 'unschedule'])->whereNumber('id');
        Route::post('/admin/content/announcements/{id}/resend-email', [SystemAnnouncementController::class, 'resendEmail'])->whereNumber('id');
        Route::delete('/admin/content/announcements/{id}', [SystemAnnouncementController::class, 'destroy'])->whereNumber('id');

        // Đăng ký — quyết định tự động / xác nhận hàng loạt
        Route::patch('/admin/registrations/{id}/auto-decision', [RegistrationController::class, 'patchAutoDecision']);
        Route::post('/admin/registrations/{id}/confirm', [RegistrationController::class, 'confirmSingle']);
        Route::post('/admin/registration-periods/{id}/confirm-batch', [RegistrationController::class, 'confirmBatch']);

        // Xác minh tiêu chí ưu tiên (đơn KTX)
        Route::get('/admin/student-priority', [StudentPriorityController::class, 'index']);
        Route::patch('/admin/student-priority/{id}/verify', [StudentPriorityController::class, 'verify']);

        // Phân phòng tự động
        Route::post('/admin/rooms/auto-assign', [AutoRoomAssignmentController::class, 'autoAssign']);
        Route::post('/admin/rooms/confirm-proposals', [AutoRoomAssignmentController::class, 'confirmProposals']);

        // Dashboard admin
        Route::get('/admin/dashboard', [DashboardController::class, 'index']);
        Route::get('/admin/dashboard/finance', [DashboardController::class, 'finance']);
        Route::get('/admin/dashboard/revenue-trend', [DashboardController::class, 'revenueTrend']);

        // Yêu cầu hỗ trợ (admin)
        Route::get('/admin/support-requests', [StudentSupportRequestController::class, 'adminIndex']);
        Route::get('/admin/support-requests/{id}', [StudentSupportRequestController::class, 'adminShow']);
        Route::put('/admin/support-requests/{id}/status', [StudentSupportRequestController::class, 'updateStatus']);
        Route::put('/admin/support-requests/{id}/process', [StudentSupportRequestController::class, 'process']);
        Route::put('/admin/support-requests/{id}/approve', [StudentSupportRequestController::class, 'approve']);
        Route::put('/admin/support-requests/{id}/reject', [StudentSupportRequestController::class, 'reject']);
        Route::put('/admin/support-requests/{id}/complete', [StudentSupportRequestController::class, 'complete']);

        // Chi tiết lưu trú (admin)
        Route::get('/admin/occupancies/{id}/detail', [OccupancyController::class, 'detail'])->whereNumber('id');

        // Tìm kiếm sinh viên
        Route::get('/admin/students/search', [StudentSearchController::class, 'search']);
        Route::post('/admin/students/face-search', [StudentFaceSearchController::class, 'search']);

        // Thông báo admin
        Route::get('/admin/notifications/unread-count', [AdminNotificationController::class, 'unreadCount']);
        Route::get('/admin/notifications', [AdminNotificationController::class, 'index']);
        Route::put('/admin/notifications/read-all', [AdminNotificationController::class, 'markAllRead']);
        Route::put('/admin/notifications/{id}/read', [AdminNotificationController::class, 'markRead'])->whereNumber('id');

        // Gia hạn lưu trú (admin)
        Route::get('/admin/extensions/stats', [OccupancyExtensionController::class, 'stats']);
        Route::get('/admin/extensions', [OccupancyExtensionController::class, 'adminIndex']);
        Route::get('/admin/extensions/{id}', [OccupancyExtensionController::class, 'adminShow']);
        Route::put('/admin/extensions/{id}/approve', [OccupancyExtensionController::class, 'approve']);
        Route::put('/admin/extensions/{id}/reject', [OccupancyExtensionController::class, 'reject']);

        // Tân sinh viên — quản lý thí sinh trúng tuyển
        Route::get('/admin/admission-candidates', [AdmissionCandidateController::class, 'index']);
        Route::post('/admin/admission-candidates', [AdmissionCandidateController::class, 'store']);
        Route::get('/admin/admission-candidates/import-template', [AdmissionCandidateController::class, 'importTemplate']);
        Route::post('/admin/admission-candidates/bulk-enroll', [AdmissionCandidateController::class, 'bulkEnroll']);
        Route::get('/admin/admission-candidates/{id}', [AdmissionCandidateController::class, 'show'])->whereNumber('id');
        Route::put('/admin/admission-candidates/{id}', [AdmissionCandidateController::class, 'update'])->whereNumber('id');
        Route::delete('/admin/admission-candidates/{id}', [AdmissionCandidateController::class, 'destroy'])->whereNumber('id');
        Route::post('/admin/admission-candidates/{id}/enroll', [AdmissionCandidateController::class, 'enroll'])->whereNumber('id');

        // Tân sinh viên — xác minh tiêu chí ưu tiên hồ sơ giữ chỗ
        Route::get('/admin/reservation-priorities', [ReservationPriorityController::class, 'index']);
        Route::patch('/admin/reservation-priorities/{id}/verify', [ReservationPriorityController::class, 'verify'])->whereNumber('id');
        Route::patch('/admin/reservation-priorities/{id}/reject', [ReservationPriorityController::class, 'reject'])->whereNumber('id');

        // Tân sinh viên — xếp hạng + batch convert + quản lý hồ sơ giữ chỗ KTX
        Route::post('/admin/dorm-reservations/rank', [DormReservationController::class, 'rankReservations']);
        Route::post('/admin/dorm-reservations/batch-convert', [DormReservationController::class, 'batchConvert']);
        Route::get('/admin/dorm-reservations', [DormReservationController::class, 'index']);
        Route::get('/admin/dorm-reservations/{id}', [DormReservationController::class, 'show'])->whereNumber('id');
        Route::put('/admin/dorm-reservations/{id}/approve', [DormReservationController::class, 'approve'])->whereNumber('id');
        Route::put('/admin/dorm-reservations/{id}/reject', [DormReservationController::class, 'reject'])->whereNumber('id');
        Route::put('/admin/dorm-reservations/{id}/waitlist', [DormReservationController::class, 'waitlist'])->whereNumber('id');
        Route::put('/admin/dorm-reservations/{id}/cancel', [DormReservationController::class, 'cancel'])->whereNumber('id');
        Route::put('/admin/dorm-reservations/{id}/note', [DormReservationController::class, 'note'])->whereNumber('id');
        Route::post('/admin/dorm-reservations/{id}/convert-to-registration', [DormReservationController::class, 'convertToRegistration'])->whereNumber('id');

        // Quản lý đợt đăng ký (Bước 2F / Lượt A) — GET /registration-periods (index) giữ public riêng,
        // được gọi từ trang chủ/trang tân sinh viên chưa đăng nhập.
        Route::post('/registration-periods', [RegistrationPeriodController::class, 'store']);
        Route::get('/registration-periods/{id}', [RegistrationPeriodController::class, 'show']);
        Route::put('/registration-periods/{id}', [RegistrationPeriodController::class, 'update']);
        Route::delete('/registration-periods/{id}', [RegistrationPeriodController::class, 'destroy']);
        Route::post('/registration-periods/{id}/process', [RegistrationPeriodController::class, 'process']);

        // Đăng ký nội trú — quản trị (Bước 2F / Lượt A)
        Route::put('/registration/{id}/approve', [RegistrationController::class, 'approve']);
        Route::put('/registration/{id}/reject', [RegistrationController::class, 'reject']);
        Route::put('/registration/{id}/assign-room', [RegistrationController::class, 'assignRoom']);
        Route::put('/registration/{id}/approve-bed', [RegistrationController::class, 'approveBed']);
        Route::put('/registration/{id}/reject-bed', [RegistrationController::class, 'rejectBed']);
        Route::put('/registration/{id}/confirm-checkout', [RegistrationController::class, 'confirmCheckout']);
        Route::put('/registration/{id}/force-checkout', [RegistrationController::class, 'forceCheckout']);
    });

    // Dùng chung cho cả admin và student — mỗi bên tự lọc phạm vi dữ liệu ngay trong
    // controller (admin: toàn bộ; student: chỉ đơn của chính mình / cùng phòng).
    Route::middleware('role:admin,student')->group(function () {
        Route::get('/registration', [RegistrationController::class, 'index']);
        Route::get('/registration/{id}', [RegistrationController::class, 'show']);

        // Danh sách phòng/giường (Lượt B) — sinh viên dùng khi chọn giường lúc đăng ký,
        // admin dùng để quản lý. Không có tham số nhận danh tính từ client.
        Route::get('/rooms', [\App\Http\Controllers\Api\RoomController::class, 'index']);
        Route::get('/rooms/{roomId}/beds', [\App\Http\Controllers\Api\RoomController::class, 'beds']);

        // Đợt gia hạn lưu trú (Lượt C) — GET index dùng chung: sinh viên dùng để lấy đợt đang
        // mở khi xem trang gia hạn, admin dùng để quản lý danh sách đợt.
        Route::get('/occupancy-periods', [OccupancyPeriodController::class, 'index']);

        // Quản lý vi phạm (Lượt C) — GET index dùng chung: sinh viên xem hoạt động/vi phạm
        // của chính mình ("Hoạt động của tôi", "Phòng của tôi"), admin xem toàn bộ + lọc.
        // Controller đã ép student chỉ thấy đúng student_id của mình, bỏ qua filter client gửi.
        Route::get('/violations', [ViolationController::class, 'index']);
    });

    Route::middleware('role:admin')->group(function () {
        // Quản lý phòng — ghi (Lượt B)
        Route::post('/rooms', [\App\Http\Controllers\Api\RoomController::class, 'store']);
        Route::put('/rooms/{roomId}', [\App\Http\Controllers\Api\RoomController::class, 'update']);
        Route::delete('/rooms/{roomId}', [\App\Http\Controllers\Api\RoomController::class, 'destroy']);
        Route::put('/rooms/{roomId}/beds/{bedId}/transfer', [\App\Http\Controllers\Api\RoomController::class, 'transferBedOccupancy']);
        Route::put('/rooms/{roomId}/beds/{bedId}', [\App\Http\Controllers\Api\RoomController::class, 'updateBed']);

        // Bảo trì giường/phòng (Lượt B) — xác nhận 100% chỉ admin dùng.
        Route::get('/maintenance/rooms/{roomId}/beds/{bedId}/plan', [MaintenanceController::class, 'bedPlan']);
        Route::post('/maintenance/rooms/{roomId}/beds/{bedId}/start', [MaintenanceController::class, 'startBedMaintenance']);
        Route::post('/maintenance/rooms/{roomId}/beds/{bedId}/complete', [MaintenanceController::class, 'completeBedMaintenance']);
        Route::get('/maintenance/rooms/{roomId}/plan', [MaintenanceController::class, 'roomPlan']);
        Route::post('/maintenance/rooms/{roomId}/start', [MaintenanceController::class, 'startRoomMaintenance']);
        Route::post('/maintenance/rooms/{roomId}/complete-student/{occupancyId}', [MaintenanceController::class, 'completeRoomMaintenanceStudent']);
        Route::post('/maintenance/rooms/{roomId}/complete', [MaintenanceController::class, 'completeRoomMaintenance']);
        Route::get('/maintenance/requests/{requestId}/room', [MaintenanceController::class, 'getRequestRoom']);

        // Quản lý tòa (Lượt B) — xác nhận 100% chỉ admin dùng.
        Route::get('/buildings', [\App\Http\Controllers\Api\BuildingController::class, 'index']);
        Route::post('/buildings', [\App\Http\Controllers\Api\BuildingController::class, 'store']);
        Route::get('/buildings/{buildingCode}', [\App\Http\Controllers\Api\BuildingController::class, 'show']);
        Route::put('/buildings/{buildingCode}', [\App\Http\Controllers\Api\BuildingController::class, 'update']);
        Route::delete('/buildings/{buildingCode}', [\App\Http\Controllers\Api\BuildingController::class, 'destroy']);
        Route::post('/buildings/{buildingCode}/floors', [\App\Http\Controllers\Api\BuildingController::class, 'storeFloor']);
        Route::put('/buildings/{buildingCode}/floors/{floorNumber}', [\App\Http\Controllers\Api\BuildingController::class, 'updateFloor']);
        Route::delete('/buildings/{buildingCode}/floors/{floorNumber}', [\App\Http\Controllers\Api\BuildingController::class, 'destroyFloor']);

        // Quản lý loại vi phạm (Lượt C)
        Route::get('/violation-types', [ViolationTypeController::class, 'index']);
        Route::post('/violation-types', [ViolationTypeController::class, 'store']);
        Route::put('/violation-types/{id}', [ViolationTypeController::class, 'update']);
        Route::delete('/violation-types/{id}', [ViolationTypeController::class, 'destroy']);

        // Quản lý vi phạm (Lượt C) — ghi
        Route::post('/violations', [ViolationController::class, 'store']);
        Route::put('/violations/{id}', [ViolationController::class, 'update']);
        Route::put('/violations/{id}/process', [ViolationController::class, 'process']);
        Route::delete('/violations/{id}', [ViolationController::class, 'destroy']);

        // Đợt gia hạn lưu trú — ghi/chi tiết (Lượt C)
        Route::post('/occupancy-periods', [OccupancyPeriodController::class, 'store']);
        // Đặt TRƯỚC route {id} — nếu không "suggestion" sẽ bị route động nuốt mất, hiểu nhầm thành id.
        Route::get('/occupancy-periods/suggestion', [OccupancyPeriodController::class, 'suggestion']);
        Route::get('/occupancy-periods/{id}', [OccupancyPeriodController::class, 'show']);
        Route::put('/occupancy-periods/{id}', [OccupancyPeriodController::class, 'update']);
        Route::delete('/occupancy-periods/{id}', [OccupancyPeriodController::class, 'destroy']);
        Route::put('/occupancy-periods/{id}/open', [OccupancyPeriodController::class, 'open']);
        Route::put('/occupancy-periods/{id}/close', [OccupancyPeriodController::class, 'close']);
    });
});

// Route công khai (không yêu cầu đăng nhập) — giới hạn tốc độ chung (60 req/phút/IP), trừ
// /storage/{path} (phục vụ ảnh, lưu lượng hợp lệ cao, không throttle chung ở đây).
Route::middleware('throttle:60,1')->group(function () {
    // Webhook VNPay — VNPay redirect thẳng trình duyệt người dùng vào đây sau khi thanh toán,
    // KHÔNG thể đính kèm Bearer token. Bảo mật dựa trên chữ ký HMAC-SHA512, giữ nguyên public.
    Route::get('/payments/vnpay/return', [VnpayPaymentController::class, 'handleReturn']);

    // Danh mục tỉnh/thành (dropdown form đăng ký)
    Route::get('/provinces', [ProvinceController::class, 'index']);

    // Tiêu chí ưu tiên (dùng cho form đăng ký)
    Route::get('/priority-criteria', [PriorityCriteriaController::class, 'index']);

    // Quản lý đợt đăng ký — chỉ GET (index) giữ public, được gọi từ trang chủ/tân sinh viên
    // chưa đăng nhập; các route ghi/chi tiết đã chuyển vào group role:admin ở trên.
    Route::get('/registration-periods', [RegistrationPeriodController::class, 'index']);
});

// Storage routes for Railway volume (must be before any wildcard routes)
Route::get('/storage/{path}', [StorageController::class, 'serveImage'])->where('path', '.*');

// =====================================================================
// Tân sinh viên — hồ sơ trúng tuyển & giữ chỗ KTX
// =====================================================================

Route::middleware('throttle:60,1')->group(function () {
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
});
