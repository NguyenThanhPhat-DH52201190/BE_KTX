<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentSettingController extends Controller
{
    private const DEFAULTS = [
        'room_fee_per_month' => 350000,
        'electricity_unit_price' => 2900,
    ];

    public function show(): JsonResponse
    {
        return response()->json($this->settings());
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'room_fee_per_month' => ['nullable', 'integer', 'gt:0'],
            'electricity_unit_price' => ['nullable', 'integer', 'gt:0'],
        ]);

        foreach ($data as $key => $value) {
            if (!array_key_exists($key, self::DEFAULTS)) {
                continue;
            }

            DB::table('settings')->updateOrInsert(
                ['key' => $key],
                [
                    'value' => (string) $value,
                    'description' => $this->description($key),
                ],
            );
        }

        return response()->json($this->settings());
    }

    private function settings(): array
    {
        $rows = DB::table('settings')
            ->whereIn('key', array_keys(self::DEFAULTS))
            ->pluck('value', 'key');

        return [
            'room_fee_per_month' => $this->positiveInt($rows['room_fee_per_month'] ?? null, self::DEFAULTS['room_fee_per_month']),
            'electricity_unit_price' => $this->positiveInt($rows['electricity_unit_price'] ?? null, self::DEFAULTS['electricity_unit_price']),
        ];
    }

    private function positiveInt(mixed $value, int $fallback): int
    {
        $parsed = (int) $value;

        return $parsed > 0 ? $parsed : $fallback;
    }

    private function description(string $key): string
    {
        return match ($key) {
            'room_fee_per_month' => 'Muc phi phong mac dinh theo thang',
            'electricity_unit_price' => 'Don gia dien mac dinh moi kWh',
            default => '',
        };
    }
}
