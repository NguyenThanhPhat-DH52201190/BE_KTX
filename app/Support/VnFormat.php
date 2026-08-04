<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Chuẩn hoá cách hiển thị ngày/giờ/tiền tệ trong các view PDF — trước đây mỗi
 * controller tự gọi Carbon::format('d/m/Y') hoặc number_format(...) rời rạc.
 */
final class VnFormat
{
    public static function date(mixed $date): string
    {
        if (empty($date)) {
            return '—';
        }

        return Carbon::parse($date)->format('d/m/Y');
    }

    public static function datetime(mixed $date): string
    {
        if (empty($date)) {
            return '—';
        }

        return Carbon::parse($date)->format('d/m/Y H:i');
    }

    public static function currency(mixed $amount): string
    {
        if ($amount === null || $amount === '') {
            return '—';
        }

        return number_format((float) $amount, 0, ',', '.') . ' đ';
    }
}
