@extends('pdf.layout')

@section('title', 'Danh sách phòng')

@php
    $statusLabels = ['AVAILABLE' => 'Còn trống', 'FULL' => 'Đầy', 'MAINTENANCE' => 'Bảo trì'];
    $genderLabels = ['MALE' => 'Nam', 'FEMALE' => 'Nữ'];
@endphp

@section('content')
    <table class="pdf-table">
        <thead>
            <tr>
                <th class="text-center" style="width: 24px;">STT</th>
                <th>Mã phòng</th>
                <th class="text-center">Tầng</th>
                <th class="text-center">Giới tính</th>
                <th class="text-center">Sức chứa</th>
                <th class="text-center">Trạng thái</th>
                <th class="text-center">Đã ở</th>
                <th class="text-center">Trống</th>
                <th class="text-center">Bảo trì</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rooms as $index => $room)
                <tr>
                    <td class="text-center">{{ $index + 1 }}</td>
                    <td>{{ $room['building_code'] }}{{ $room['room_number'] }}</td>
                    <td class="text-center">{{ $room['floor_number'] }}</td>
                    <td class="text-center">{{ $genderLabels[$room['floor']['gender'] ?? ''] ?? '—' }}</td>
                    <td class="text-center">{{ $room['capacity'] }}</td>
                    <td class="text-center">{{ $statusLabels[$room['status']] ?? $room['status'] }}</td>
                    <td class="text-center">{{ $room['occupied_beds'] }}</td>
                    <td class="text-center">{{ $room['available_beds'] }}</td>
                    <td class="text-center">{{ $room['maintenance_beds'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="9" class="text-center">Không có dữ liệu.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <p style="font-size: 10px; color: #5a78a8;">Tổng số: {{ count($rooms) }} phòng.</p>
@endsection
