@extends('pdf.layout')

@section('title', 'Hồ sơ lưu trú')

@php
    $occStatusLabels = [
        'ACTIVE' => 'Đang lưu trú',
        'PENDING_PAYMENT' => 'Chờ thanh toán',
        'CHECKOUT_REQUESTED' => 'Yêu cầu thôi ở',
        'COMPLETED' => 'Đã thôi ở',
        'TERMINATED' => 'Đã kết thúc',
    ];
    $billStatusLabels = ['unpaid' => 'Chưa thanh toán', 'paid' => 'Đã thanh toán', 'overdue' => 'Quá hạn', 'exempted' => 'Đã miễn'];
@endphp

@section('content')
    <div class="info-box">
        <div class="row"><span class="label">MSSV</span>{{ $identity['student_code'] }}</div>
        <div class="row"><span class="label">Họ tên</span>{{ $identity['full_name'] }}</div>
        <div class="row"><span class="label">Phòng</span>{{ $identity['building_code'] }}{{ $identity['room_number'] }}</div>
        <div class="row"><span class="label">Giường</span>{{ $identity['bed_number'] }}</div>
        <div class="row"><span class="label">Ngày nhận phòng</span>{{ \App\Support\VnFormat::date($identity['check_in_date']) }}</div>
        <div class="row"><span class="label">Ngày trả phòng</span>{{ \App\Support\VnFormat::date($identity['check_out_date']) }}</div>
        <div class="row"><span class="label">Trạng thái</span>{{ $occStatusLabels[$identity['status']] ?? $identity['status'] }}</div>
        @if(!empty($student['permanent_address']))
            <div class="row"><span class="label">Địa chỉ thường trú</span>{{ $student['permanent_address'] }}</div>
        @endif
    </div>

    @if(!empty(array_filter($family)))
        <h2 class="section-title">Thông tin gia đình</h2>
        <div class="info-box">
            <div class="row"><span class="label">Họ tên cha</span>{{ $family['father_name'] ?? '—' }} @if(!empty($family['father_phone'])) — {{ $family['father_phone'] }} @endif</div>
            <div class="row"><span class="label">Họ tên mẹ</span>{{ $family['mother_name'] ?? '—' }} @if(!empty($family['mother_phone'])) — {{ $family['mother_phone'] }} @endif</div>
            @if(!empty($family['parent_address']))
                <div class="row"><span class="label">Địa chỉ gia đình</span>{{ $family['parent_address'] }}</div>
            @endif
        </div>
    @endif

    <h2 class="section-title">Lịch sử lưu trú</h2>
    <table class="pdf-table">
        <thead>
            <tr>
                <th>Kỳ</th>
                <th>Phòng</th>
                <th>Giường</th>
                <th style="width: 60px;">Nhận phòng</th>
                <th style="width: 60px;">Trả phòng</th>
                <th class="text-center">Trạng thái</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($occupancy_history as $item)
                <tr>
                    <td>{{ $item['period_name'] ?? trim(($item['school_year'] ?? '') . ' ' . ($item['semester'] ?? '')) }}</td>
                    <td>{{ $item['building_code'] }}{{ $item['room_number'] }}</td>
                    <td class="text-center">{{ $item['bed_number'] }}</td>
                    <td>{{ \App\Support\VnFormat::date($item['check_in_date']) }}</td>
                    <td>{{ \App\Support\VnFormat::date($item['check_out_date']) }}</td>
                    <td class="text-center">{{ $occStatusLabels[$item['status']] ?? $item['status'] }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center">Không có dữ liệu.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2 class="section-title">Vi phạm gần đây</h2>
    <table class="pdf-table">
        <thead>
            <tr>
                <th style="width: 60px;">Ngày</th>
                <th>Loại</th>
                <th class="text-center">Mức độ</th>
                <th>Ghi chú</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($recent_violations as $violation)
                <tr>
                    <td>{{ \App\Support\VnFormat::date($violation['activity_date']) }}</td>
                    <td>{{ $violation['type_name'] }}</td>
                    <td class="text-center">{{ $violation['level'] }}</td>
                    <td>{{ $violation['note'] ?: '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="text-center">Không có vi phạm gần đây.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2 class="section-title">Hóa đơn &amp; công nợ</h2>
    <div class="info-box">
        <div class="row">
            <span class="label">Hóa đơn hiện tại</span>
            @if($current_invoice)
                Tháng {{ $current_invoice['month'] }}/{{ $current_invoice['year'] }}
                — {{ \App\Support\VnFormat::currency($current_invoice['amount']) }}
                — {{ $billStatusLabels[$current_invoice['status']] ?? $current_invoice['status'] }}
            @else
                Không có
            @endif
        </div>
        <div class="row"><span class="label">Tổng công nợ chưa thanh toán</span>{{ \App\Support\VnFormat::currency($total_debt) }}</div>
        <div class="row"><span class="label">Nợ quá hạn/chưa thanh toán</span>{{ \App\Support\VnFormat::currency($unpaid_debt) }}</div>
        @if($checkout_request)
            <div class="row"><span class="label">Yêu cầu thôi ở</span>Dự kiến {{ \App\Support\VnFormat::date($checkout_request['expected_leave_date']) }} — {{ $checkout_request['reason'] ?: '—' }}</div>
        @endif
    </div>

    @if(count($room_change_history) > 0)
        <h2 class="section-title">Lịch sử đổi phòng</h2>
        <table class="pdf-table">
            <thead>
                <tr>
                    <th style="width: 60px;">Ngày</th>
                    <th>Từ</th>
                    <th>Đến</th>
                    <th>Lý do</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($room_change_history as $change)
                    <tr>
                        <td>{{ \App\Support\VnFormat::date($change['transferred_at']) }}</td>
                        <td>{{ $change['old_room_code'] ?? '—' }} @if($change['old_bed_number']) - #{{ $change['old_bed_number'] }} @endif</td>
                        <td>{{ $change['new_room_code'] ?? '—' }} @if($change['new_bed_number']) - #{{ $change['new_bed_number'] }} @endif</td>
                        <td>{{ $change['transfer_reason'] ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endsection
