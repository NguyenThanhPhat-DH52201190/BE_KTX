<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Registration;
use App\Models\StudentPriority;
use App\Services\PriorityRankingService;
use Illuminate\Http\Request;

class StudentPriorityController extends Controller
{
    private function getImageUrl(?string $path): ?string
    {
        if (empty($path)) return null;
        if (filter_var($path, FILTER_VALIDATE_URL)) return $path;
        $cleanPath = ltrim($path, '/');
        $isProduction = app()->environment('production') || env('RAILWAY_ENVIRONMENT') === 'production';
        return $isProduction ? url('/api/storage/' . $cleanPath) : url('/storage/' . $cleanPath);
    }

    public function index(Request $request)
    {
        $registrationId = $request->query('registration_id');

        if (!$registrationId) {
            return response()->json(['message' => 'registration_id là bắt buộc'], 422);
        }

        $items = StudentPriority::with(['criteria', 'evidences', 'verifier'])
            ->where('registration_id', $registrationId)
            ->get()
            ->map(function ($p) {
                $urls = $p->evidences
                    ->map(fn($e) => $this->getImageUrl($e->file_url))
                    ->filter()
                    ->values()
                    ->all();
                if (empty($urls) && $p->evidence_url) {
                    $urls = [$this->getImageUrl($p->evidence_url)];
                }
                return [
                    'id'             => $p->id,
                    'criteria_id'    => $p->priority_criteria_id,
                    'code'           => $p->criteria?->code,
                    'name'           => $p->criteria?->name,
                    'tier'           => $p->criteria?->tier,
                    'priority_score' => $p->criteria?->priority_score,
                    'evidence_urls'  => $urls,
                    'status'         => $p->status,
                    'verified_by'    => $p->verified_by,
                    'verified_at'    => $p->verified_at?->toDateTimeString(),
                    'verifier_name'  => $p->verifier?->full_name,
                ];
            });

        return response()->json($items);
    }

    public function verify(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:verified,rejected',
            'note'   => 'nullable|string|max:500',
        ]);

        $priority = StudentPriority::findOrFail($id);

        $priority->status      = $request->status;
        // Route đã bảo vệ auth:sanctum + role:admin — lấy id từ $request->user() thay vì
        // auth()->id() (guard mặc định 'web', luôn null với request Sanctum bearer-token).
        $priority->verified_by = $request->user()?->id;
        $priority->verified_at = now();
        $priority->save();

        $registration = Registration::find($priority->registration_id);
        if ($registration) {
            (new PriorityRankingService())->calculateForRegistration($registration);
            $registration->refresh();
        }

        return response()->json([
            'id'                   => $priority->id,
            'status'               => $priority->status,
            'verified_at'          => $priority->verified_at?->toDateTimeString(),
            'top_priority_tier'    => $registration?->top_priority_tier,
            'total_priority_score' => $registration?->total_priority_score,
        ]);
    }
}
