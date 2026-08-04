@extends('pdf.layout')

@section('title', 'Danh sách lưu trú')

@php
    $statusLabels = [
        'ACTIVE' => 'Đang lưu trú',
        'PENDING_PAYMENT' => 'Chờ thanh toán',
        'ROOM_CONFIRMED' => 'Đã xác nhận phòng',
        'PROPOSED' => 'Đề xuất phòng',
        'CHECKOUT_REQUESTED' => 'Yêu cầu thôi ở',
        'COMPLETED' => 'Đã thôi ở',
        'TERMINATED' => 'Đã kết thúc',
    ];
@endphp

@section('content')
    <table class="pdf-table">
        <thead>
            <tr>
                <th class="text-center" style="width: 24px;">STT</th>
                <th>MSSV</th>
                <th>Họ tên</th>
                <th>Phòng</th>
                <th>Giường</th>
                <th style="width: 60px;">Ngày nhận phòng</th>
                <th style="width: 60px;">Ngày trả phòng</th>
                <th class="text-center">Trạng thái</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($occupancies as $index => $item)
                <tr>
                    <td class="text-center">{{ $index + 1 }}</td>
                    <td>{{ $item['student_code'] }}</td>
                    <td>{{ $item['full_name'] }}</td>
                    <td>{{ $item['building_code'] }}{{ $item['room_number'] }}</td>
                    <td class="text-center">{{ $item['bed_number'] }}</td>
                    <td>{{ \App\Support\VnFormat::date($item['check_in_date']) }}</td>
                    <td>{{ \App\Support\VnFormat::date($item['check_out_date']) }}</td>
                    <td class="text-center">{{ $statusLabels[$item['status']] ?? $item['status'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="text-center">Không có dữ liệu.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <p style="font-size: 10px; color: #5a78a8;">Tổng số: {{ count($occupancies) }} bản ghi.</p>
@endsection
