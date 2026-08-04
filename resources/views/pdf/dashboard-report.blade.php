@extends('pdf.layout')

@section('title', 'Báo cáo thống kê tổng hợp')

@section('content')
    <h2 class="section-title">Thống kê tổng quan</h2>
    <table class="pdf-table">
        <tbody>
            <tr><td>Tổng sinh viên đang ở</td><td class="text-right">{{ $stats['total_students'] }}</td>
                <td>Tổng giường</td><td class="text-right">{{ $stats['total_beds'] }}</td></tr>
            <tr><td>Sinh viên nam / nữ</td><td class="text-right">{{ $stats['male_count'] }} / {{ $stats['female_count'] }}</td>
                <td>Giường đã ở / khả dụng</td><td class="text-right">{{ $stats['occupied_beds'] }} / {{ $stats['available_beds'] }}</td></tr>
            <tr><td>Phòng đang sử dụng</td><td class="text-right">{{ $stats['occupied_rooms'] }}</td>
                <td>Giường bảo trì</td><td class="text-right">{{ $stats['maintenance_beds'] }}</td></tr>
            <tr><td>Tổng số phòng</td><td class="text-right">{{ $stats['total_rooms'] }}</td>
                <td>Đơn đăng ký chờ duyệt</td><td class="text-right">{{ $stats['pending_registrations'] }}</td></tr>
        </tbody>
    </table>

    <h2 class="section-title">Cảnh báo cần xử lý</h2>
    <table class="pdf-table">
        <tbody>
            <tr><td>Hóa đơn quá hạn</td><td class="text-right">{{ $alerts['overdue_invoices'] }}</td>
                <td>Lưu trú sắp hết hạn (30 ngày)</td><td class="text-right">{{ $alerts['expiring_occupancies_30d'] }}</td></tr>
            <tr><td>Chờ chọn giường</td><td class="text-right">{{ $alerts['pending_bed_selection'] }}</td>
                <td>Yêu cầu đổi phòng chờ duyệt</td><td class="text-right">{{ $alerts['pending_room_changes'] }}</td></tr>
            <tr><td>Yêu cầu đổi giường chờ duyệt</td><td class="text-right">{{ $alerts['pending_bed_changes'] }}</td>
                <td>Yêu cầu thôi ở chờ duyệt</td><td class="text-right">{{ $alerts['pending_checkouts'] }}</td></tr>
            <tr><td>Yêu cầu hỗ trợ chờ xử lý</td><td class="text-right">{{ $alerts['pending_support_requests'] }}</td>
                <td></td><td class="text-right"></td></tr>
        </tbody>
    </table>

    @if($current_period)
        <h2 class="section-title">Đợt đăng ký hiện tại</h2>
        <div class="info-box">
            <div class="row"><span class="label">Tên đợt</span>{{ $current_period['name'] }} ({{ $current_period['school_year'] }} - {{ $current_period['semester'] }})</div>
            <div class="row"><span class="label">Đơn đăng ký</span>
                Tổng {{ $current_period['registrations']['total'] }}
                — Chờ duyệt {{ $current_period['registrations']['pending'] }}
                — Đã duyệt {{ $current_period['registrations']['approved'] }}
                — Từ chối {{ $current_period['registrations']['rejected'] }}
            </div>
            <div class="row"><span class="label">Phân phòng</span>
                Chưa xếp {{ $current_period['room_assignment']['not_assigned'] }}
                — Đề xuất {{ $current_period['room_assignment']['proposed'] }}
                — Đã xác nhận phòng {{ $current_period['room_assignment']['room_confirmed'] }}
                — Đang ở {{ $current_period['room_assignment']['active'] }}
            </div>
        </div>
    @endif

    <h2 class="section-title">Tài chính tháng {{ $finance['month'] }}/{{ $finance['year'] }}</h2>
    <table class="pdf-table">
        <thead>
            <tr>
                <th></th>
                <th class="text-right">Dự kiến thu</th>
                <th class="text-right">Đã thu</th>
                <th class="text-right">Còn nợ</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Tiền phòng</td>
                <td class="text-right">{{ \App\Support\VnFormat::currency($finance['room_expected_total']) }}</td>
                <td class="text-right">{{ \App\Support\VnFormat::currency($finance['room_collected']) }}</td>
                <td class="text-right">{{ \App\Support\VnFormat::currency($finance['room_debt']) }}</td>
            </tr>
            <tr>
                <td>Tiền điện</td>
                <td class="text-right">{{ \App\Support\VnFormat::currency($finance['electricity_expected_total']) }}</td>
                <td class="text-right">{{ \App\Support\VnFormat::currency($finance['electricity_collected']) }}</td>
                <td class="text-right">{{ \App\Support\VnFormat::currency($finance['electricity_debt']) }}</td>
            </tr>
        </tbody>
        <tfoot>
            <tr>
                <td>Tổng cộng ({{ $finance['debtors_count'] }} sinh viên còn nợ)</td>
                <td class="text-right">{{ \App\Support\VnFormat::currency($finance['expected_total']) }}</td>
                <td class="text-right">{{ \App\Support\VnFormat::currency($finance['collected']) }}</td>
                <td class="text-right">{{ \App\Support\VnFormat::currency($finance['debt']) }}</td>
            </tr>
        </tfoot>
    </table>

    <h2 class="section-title">Sinh viên theo khoa</h2>
    <table class="pdf-table">
        <thead>
            <tr><th>Khoa</th><th class="text-right">Nam</th><th class="text-right">Nữ</th><th class="text-right">Tổng</th></tr>
        </thead>
        <tbody>
            @forelse ($charts['by_faculty'] as $row)
                <tr>
                    <td>{{ $row['faculty'] }}</td>
                    <td class="text-right">{{ $row['male'] }}</td>
                    <td class="text-right">{{ $row['female'] }}</td>
                    <td class="text-right">{{ $row['total'] }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="text-center">Không có dữ liệu.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2 class="section-title">Sinh viên theo năm học</h2>
    <table class="pdf-table">
        <thead>
            <tr><th>Năm</th><th class="text-right">Nam</th><th class="text-right">Nữ</th><th class="text-right">Tổng</th></tr>
        </thead>
        <tbody>
            @forelse ($charts['by_year'] as $row)
                <tr>
                    <td>Năm {{ $row['year'] }}</td>
                    <td class="text-right">{{ $row['male'] }}</td>
                    <td class="text-right">{{ $row['female'] }}</td>
                    <td class="text-right">{{ $row['total'] }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="text-center">Không có dữ liệu.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2 class="section-title">Sinh viên theo tỉnh/thành</h2>
    <table class="pdf-table">
        <thead>
            <tr><th>Tỉnh/Thành</th><th class="text-right">Số lượng</th></tr>
        </thead>
        <tbody>
            @forelse ($charts['by_province'] as $row)
                <tr>
                    <td>{{ $row['province'] }}</td>
                    <td class="text-right">{{ $row['count'] }}</td>
                </tr>
            @empty
                <tr><td colspan="2" class="text-center">Không có dữ liệu.</td></tr>
            @endforelse
        </tbody>
    </table>
@endsection
