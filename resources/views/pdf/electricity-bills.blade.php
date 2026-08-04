@extends('pdf.layout')

@section('title', 'Danh sách hóa đơn tiền điện')

@php
    $statusLabels = ['unpaid' => 'Chưa thanh toán', 'paid' => 'Đã thanh toán', 'overdue' => 'Quá hạn', 'exempted' => 'Đã miễn'];
    $total = collect($bills)->sum('amount');
@endphp

@section('content')
    <table class="pdf-table">
        <thead>
            <tr>
                <th class="text-center" style="width: 24px;">STT</th>
                <th>Sinh viên</th>
                <th>Phòng</th>
                <th class="text-center">Kỳ</th>
                <th class="text-right">Số điện (kWh)</th>
                <th class="text-right">Đơn giá</th>
                <th class="text-right">Thành tiền</th>
                <th style="width: 60px;">Hạn nộp</th>
                <th class="text-center">Trạng thái</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($bills as $index => $bill)
                <tr>
                    <td class="text-center">{{ $index + 1 }}</td>
                    <td>
                        {{ $bill['student']['student_code'] ?? '' }}
                        @if(!empty($bill['student']['full_name']))
                            - {{ $bill['student']['full_name'] }}
                        @endif
                    </td>
                    <td>{{ $bill['room']['building_code'] ?? '' }}{{ $bill['room']['room_number'] ?? '' }}</td>
                    <td class="text-center">{{ $bill['month_year'] }}</td>
                    <td class="text-right">{{ $bill['usage_kwh'] }}</td>
                    <td class="text-right">{{ \App\Support\VnFormat::currency($bill['unit_price']) }}</td>
                    <td class="text-right">{{ \App\Support\VnFormat::currency($bill['amount']) }}</td>
                    <td>{{ \App\Support\VnFormat::date($bill['due_date']) }}</td>
                    <td class="text-center">{{ $statusLabels[$bill['status']] ?? $bill['status'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="9" class="text-center">Không có dữ liệu.</td>
                </tr>
            @endforelse
        </tbody>
        @if(count($bills) > 0)
            <tfoot>
                <tr>
                    <td colspan="6" class="text-right">Tổng cộng ({{ count($bills) }} hóa đơn)</td>
                    <td class="text-right">{{ \App\Support\VnFormat::currency($total) }}</td>
                    <td colspan="2"></td>
                </tr>
            </tfoot>
        @endif
    </table>
@endsection
