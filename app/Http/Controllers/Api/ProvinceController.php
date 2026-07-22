<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Province;
use Illuminate\Http\JsonResponse;

class ProvinceController extends Controller
{
    /**
     * Danh sách tỉnh/thành cho dropdown đăng ký.
     * Sắp xếp theo vùng miền (region) rồi theo tên (name).
     */
    public function index(): JsonResponse
    {
        $provinces = Province::query()
            ->where('is_active', true)
            ->orderBy('region')
            ->orderBy('name')
            ->get(['code', 'name', 'region']);

        return response()->json($provinces);
    }
}
