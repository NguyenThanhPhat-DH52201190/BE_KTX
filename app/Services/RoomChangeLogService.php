<?php

namespace App\Services;

/**
 * Phân loại và dịch nhãn tiếng Việt cho các dòng room_change_log — dùng chung giữa
 * StudentRoomChangeHistoryController (lịch sử phía sinh viên) và RoomController (modal
 * "Thông tin giường" phía admin) để không lặp lại logic ở 2 nơi.
 */
class RoomChangeLogService
{
    // transfer_reason/change_type kết hợp đánh dấu bước xếp phòng/giường ban đầu khi mới vào ở
    // (không phải một lần "chuyển" thật sự — không có phòng/giường cũ).
    public const INITIAL_REASONS = ['select_bed', 'assign_room'];
    public const INITIAL_CHANGE_TYPES = ['PERMANENT', 'ADMIN_TRANSFER'];

    public const INITIAL_ASSIGNMENT_LABEL = 'Xếp chỗ ban đầu';

    public static function isInitialAssignment(object $row): bool
    {
        return in_array($row->transfer_reason, self::INITIAL_REASONS, true)
            && in_array($row->change_type, self::INITIAL_CHANGE_TYPES, true);
    }

    /**
     * Nhãn tiếng Việt cho một lần thay đổi phòng/giường THẬT SỰ (không áp dụng cho bước
     * xếp chỗ ban đầu — gọi isInitialAssignment() để lọc trước khi dùng hàm này).
     * Yêu cầu $row có các thuộc tính: is_temporary, change_type, transfer_reason,
     * change_source, old_room_code (nếu có, dùng cho câu "Chuyển tạm do bảo trì phòng X").
     *
     * @param string|null $customReason Lý do bảo trì admin tự nhập (MaintenanceRequest::reason)
     *                                  — nếu có, nối thêm vào câu nhãn chung thay vì chỉ hiện
     *                                  câu mẫu chung chung, không mất thông tin admin đã ghi
     *                                  (báo cáo 28/07: gõ "test" nhưng lịch sử luôn hiện câu
     *                                  mẫu cố định, không thấy lý do thật đã nhập).
     */
    public static function buildLabel(object $row, ?string $customReason = null): string
    {
        if ($row->is_temporary) {
            $customReason = trim((string) $customReason);
            if ($customReason !== '') {
                return $customReason;
            }

            return trim('Chuyển tạm do bảo trì phòng ' . ($row->old_room_code ?? ''));
        }

        if ($row->change_type === 'TEMPORARY_MAINTENANCE'
            || in_array($row->transfer_reason, ['ROOM_MAINTENANCE_RETURN', 'BED_MAINTENANCE_RETURN'], true)) {
            return 'Đã trả về phòng/giường cũ sau bảo trì';
        }

        if ($row->change_source === 'student_request' || $row->transfer_reason === 'student_room_change_request') {
            return 'Chuyển theo yêu cầu cá nhân (đã được duyệt)';
        }

        if ($row->change_type === 'ADMIN_TRANSFER' || str_contains((string) $row->transfer_reason, 'admin_transfer')) {
            return 'Admin điều chuyển phòng/giường';
        }

        return 'Thay đổi phòng/giường';
    }
}
