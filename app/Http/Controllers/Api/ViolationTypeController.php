<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ViolationTypeController extends Controller
{
    public function index(): JsonResponse
    {
        $types = ActivityType::query()
            ->orderBy('id')
            ->get()
            ->map(fn (ActivityType $type) => $this->formatActivityType($type))
            ->values();

        return response()->json($types);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191', 'unique:activity_types,name'],
            'level' => ['required', 'string', Rule::in(['MINOR', 'MEDIUM', 'SERIOUS'])],
            'description' => ['nullable', 'string'],
        ]);

        $type = ActivityType::query()->create([
            'name' => trim($data['name']),
            'level' => $data['level'],
            'description' => trim((string) ($data['description'] ?? '')),
        ]);

        return response()->json($this->formatActivityType($type), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $type = ActivityType::query()->findOrFail($id);

        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:191',
                Rule::unique('activity_types', 'name')->ignore($type->id),
            ],
            'level' => ['required', 'string', Rule::in(['MINOR', 'MEDIUM', 'SERIOUS'])],
            'description' => ['nullable', 'string'],
        ]);

        $type->update([
            'name' => trim($data['name']),
            'level' => $data['level'],
            'description' => trim((string) ($data['description'] ?? '')),
        ]);

        return response()->json($this->formatActivityType($type));
    }

    public function destroy(int $id): JsonResponse
    {
        $type = ActivityType::query()->findOrFail($id);

        if (DB::table('activities')->where('activity_type_id', $type->id)->exists()) {
            return response()->json(['message' => 'Không thể xóa loại vi phạm đã được sử dụng.'], 422);
        }

        $type->delete();

        return response()->noContent();
    }

    private function formatActivityType(ActivityType $type): array
    {
        return [
            'id' => (int) $type->id,
            'name' => $type->name,
            'level' => strtoupper((string) $type->level),
            'description' => $type->description ?? '',
        ];
    }
}
