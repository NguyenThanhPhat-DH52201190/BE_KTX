@extends('pdf.layout')

@section('title', 'Danh sách đơn gia hạn lưu trú đã duyệt')

@section('content')
    <table class="pdf-table">
        <thead>
            <tr>
                <th class="text-center" style="width: 24px;">STT</th>
                <th>MSSV</th>
                <th>Họ tên</th>
                <th>Phòng</th>
                <th>Giường</th>
                <th>Đợt gia hạn</th>
                <th style="width: 60px;">Ngày gửi</th>
                <th style="width: 60px;">Ngày duyệt</th>
                <th>Lý do</th>
                <th>Ghi chú admin</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($extensions as $index => $item)
                <tr>
                    <td class="text-center">{{ $index + 1 }}</td>
                    <td>{{ $item['student_code'] }}</td>
                    <td>{{ $item['full_name'] }}</td>
                    <td>{{ $item['building_code'] }}{{ $item['room_number'] }}</td>
                    <td class="text-center">{{ $item['bed_number'] }}</td>
                    <td>{{ $item['period_name'] }}</td>
                    <td>{{ \App\Support\VnFormat::date($item['requested_at']) }}</td>
                    <td>{{ \App\Support\VnFormat::date($item['approved_at']) }}</td>
                    <td>{{ $item['reason'] ?: '—' }}</td>
                    <td>{{ $item['admin_note'] ?: '—' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="10" class="text-center">Không có dữ liệu.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <p style="font-size: 10px; color: #5a78a8;">Tổng số: {{ count($extensions) }} đơn đã duyệt.</p>
@endsection
