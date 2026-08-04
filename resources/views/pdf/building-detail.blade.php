@extends('pdf.layout')

@section('title', 'Danh sách phòng/giường — Tòa ' . $building['code'])

@php
    $roomStatusLabels = ['ACTIVE' => 'Đang sử dụng', 'MAINTENANCE' => 'Bảo trì', 'INACTIVE' => 'Ngừng sử dụng'];
    $floorStatusLabels = ['ACTIVE' => 'Đang hoạt động', 'MAINTENANCE' => 'Bảo trì', 'INACTIVE' => 'Ngừng hoạt động'];
    $genderLabels = ['MALE' => 'Nam', 'FEMALE' => 'Nữ'];
@endphp

@section('content')
    <div class="info-box">
        <div class="row"><span class="label">Tòa</span>{{ $building['code'] }} — {{ $building['name'] }}</div>
        <div class="row"><span class="label">Địa chỉ</span>{{ $building['address'] ?? '—' }}</div>
    </div>

    @forelse ($floors as $floor)
        <h2 class="section-title">
            Tầng {{ $floor['floor_number'] }}
            — {{ $genderLabels[$floor['gender']] ?? $floor['gender'] }}
            — {{ $floorStatusLabels[$floor['status']] ?? $floor['status'] }}
        </h2>

        <table class="pdf-table">
            <thead>
                <tr>
                    <th class="text-center" style="width: 24px;">STT</th>
                    <th>Số phòng</th>
                    <th class="text-center">Sức chứa</th>
                    <th class="text-right">Giá/tháng</th>
                    <th class="text-center">Trạng thái</th>
                    <th class="text-center">Giường đã ở/tổng</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($floor['rooms'] as $index => $room)
                    <tr>
                        <td class="text-center">{{ $index + 1 }}</td>
                        <td>{{ $room['room_number'] }}</td>
                        <td class="text-center">{{ $room['capacity'] }}</td>
                        <td class="text-right">{{ \App\Support\VnFormat::currency($room['price_per_month']) }}</td>
                        <td class="text-center">{{ $roomStatusLabels[$room['status']] ?? $room['status'] }}</td>
                        <td class="text-center">{{ $room['occupied_count'] }}/{{ $room['bed_count'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center">Tầng chưa có phòng.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    @empty
        <p>Tòa chưa có tầng nào.</p>
    @endforelse
@endsection
