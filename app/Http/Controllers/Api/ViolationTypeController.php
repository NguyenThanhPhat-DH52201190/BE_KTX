<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ViolationType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ViolationTypeController extends Controller
{
    public function index(): JsonResponse
    {
        $types = ViolationType::query()
            ->orderBy('id')
            ->get()
            ->map(fn (ViolationType $type) => $this->formatViolationType($type))
            ->values();

        return response()->json($types);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191', 'unique:violation_types,name'],
            'level' => ['required', 'string', Rule::in(['MINOR', 'MEDIUM', 'SERIOUS'])],
            'description' => ['nullable', 'string'],
        ]);

        $type = ViolationType::query()->create([
            'name' => trim($data['name']),
            'level' => $data['level'],
            'description' => trim((string) ($data['description'] ?? '')),
        ]);

        return response()->json($this->formatViolationType($type), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $type = ViolationType::query()->findOrFail($id);

        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:191',
                Rule::unique('violation_types', 'name')->ignore($type->id),
            ],
            'level' => ['required', 'string', Rule::in(['MINOR', 'MEDIUM', 'SERIOUS'])],
            'description' => ['nullable', 'string'],
        ]);

        $type->update([
            'name' => trim($data['name']),
            'level' => $data['level'],
            'description' => trim((string) ($data['description'] ?? '')),
        ]);

        return response()->json($this->formatViolationType($type));
    }

    public function destroy(int $id): JsonResponse
    {
        $type = ViolationType::query()->findOrFail($id);

        if (DB::table('violations')->where('type_id', $type->id)->exists()) {
            return response()->json(['message' => 'Không thể xóa loại vi phạm đã được sử dụng.'], 422);
        }

        $type->delete();

        return response()->noContent();
    }

    private function formatViolationType(ViolationType $type): array
    {
        return [
            'id' => (int) $type->id,
            'name' => $type->name,
            'level' => strtoupper((string) $type->level),
            'description' => $type->description ?? '',
        ];
    }
}
