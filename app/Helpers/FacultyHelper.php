<?php

namespace App\Helpers;

class FacultyHelper
{
    /**
     * Tra bảng ánh xạ khoa (config/faculties.php) theo mã ngắn hoặc tên đầy đủ.
     * Không khớp được thì trả về null.
     */
    public static function resolveDepartmentNumber(?string $codeOrName): ?int
    {
        if (!$codeOrName) {
            return null;
        }

        $needle = self::normalize($codeOrName);

        foreach (config('faculties', []) as $number => $entry) {
            if (self::normalize($entry['name']) === $needle) {
                return (int) $number;
            }
            foreach ($entry['codes'] as $code) {
                if (self::normalize($code) === $needle) {
                    return (int) $number;
                }
            }
        }

        return null;
    }

    /**
     * Mã khoa viết tắt dùng cho class_name (VD "DDT" cho Điện - Điện tử) — luôn là
     * phần tử đầu của 'codes' trong config/faculties.php cho khoa đó. Khác với số khoa
     * dùng trong MSSV (resolveDepartmentNumber()).
     */
    public static function resolveClassAbbreviation(?string $codeOrName): ?string
    {
        $number = self::resolveDepartmentNumber($codeOrName);
        if ($number === null) {
            return null;
        }

        $entry = config('faculties', [])[$number] ?? null;

        return $entry['codes'][0] ?? null;
    }

    /**
     * 2 số năm đầu của course_year (VD "2020-2024" => "20") — dùng chung cho mọi chỗ
     * cần đồng bộ năm với course_year (expected_student_code, gợi ý class_name...),
     * không tính riêng lẻ ở từng nơi để tránh lệch nhau.
     */
    public static function extractYearTwoDigits(?string $courseYear): ?string
    {
        if (!preg_match('/(\d{4})/', (string) $courseYear, $match)) {
            return null;
        }

        return substr($match[1], -2);
    }

    private static function normalize(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
