@extends('pdf.layout')

@section('title', 'Danh sách sinh viên vi phạm')

@php
    $statusLabels = ['pending' => 'Chờ xử lý', 'resolved' => 'Đã xử lý'];
    $actionLabels = [
        'reward_recorded' => 'Đã ghi nhận',
        'reminded' => 'Nhắc nhở',
        'warned' => 'Cảnh cáo',
        'force_evicted' => 'Buộc thôi ở',
    ];
    $levelLabels = ['MINOR' => 'Nhẹ', 'MEDIUM' => 'Trung bình', 'SERIOUS' => 'Nghiêm trọng'];
@endphp

@section('content')
    <table class="pdf-table">
        <thead>
            <tr>
                <th class="text-center" style="width: 24px;">STT</th>
                <th style="width: 60px;">Ngày</th>
                <th>Sinh viên</th>
                <th>Phòng</th>
                <th>Giường</th>
                <th>Loại</th>
                <th class="text-center">Mức độ</th>
                <th class="text-center">Điểm</th>
                <th class="text-center">Trạng thái</th>
                <th>Biện pháp xử lý</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($activities as $index => $activity)
                <tr>
                    <td class="text-center">{{ $index + 1 }}</td>
                    <td>{{ \App\Support\VnFormat::date($activity['violation_date']) }}</td>
                    <td>
                        {{ $activity['student']['student_code'] ?? '' }}
                        @if(!empty($activity['student']['full_name']))
                            - {{ $activity['student']['full_name'] }}
                        @endif
                    </td>
                    <td>{{ $activity['room']['display_name'] ?? '' }}</td>
                    <td class="text-center">{{ $activity['bed']['bed_number'] ?? '' }}</td>
                    <td>{{ $activity['type']['name'] ?? '—' }}</td>
                    <td class="text-center">{{ $levelLabels[$activity['type']['level'] ?? ''] ?? ($activity['type']['level'] ?? '—') }}</td>
                    <td class="text-center">{{ $activity['type']['points'] ?? 0 }}</td>
                    <td class="text-center">{{ $statusLabels[$activity['status']] ?? $activity['status'] }}</td>
                    <td>{{ $activity['action_taken'] ? ($actionLabels[$activity['action_taken']] ?? $activity['action_taken']) : '—' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="10" class="text-center">Không có dữ liệu.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <p style="font-size: 10px; color: #5a78a8;">Tổng số: {{ count($activities) }} bản ghi.</p>
@endsection
