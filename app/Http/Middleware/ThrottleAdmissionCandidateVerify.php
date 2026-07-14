<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class ThrottleAdmissionCandidateVerify
{
    private const MAX_PER_IP = 10;
    private const MAX_PER_CODE = 5;
    private const DECAY_SECONDS = 3600;
    protected const INPUT_FIELD = 'admission_code';
    protected const KEY_PREFIX = 'admission-verify';
    protected const FALLBACK_SECRET = 'admission-candidate-verify';
    protected const TOO_MANY_ATTEMPTS_MESSAGE = 'Bạn đã thử xác minh quá nhiều lần. Vui lòng thử lại sau.';

    public function handle(Request $request, Closure $next): Response
    {
        $ipKey = static::KEY_PREFIX . ':ip:' . hash('sha256', (string) $request->ip());
        $code = trim((string) $request->input(static::INPUT_FIELD, ''));
        $codeKey = $code !== ''
            ? static::KEY_PREFIX . ':code:' . hash_hmac('sha256', mb_strtolower($code), $this->rateLimitSecret())
            : null;

        if (RateLimiter::tooManyAttempts($ipKey, self::MAX_PER_IP) || ($codeKey && RateLimiter::tooManyAttempts($codeKey, self::MAX_PER_CODE))) {
            return response()->json([
                'message' => static::TOO_MANY_ATTEMPTS_MESSAGE,
            ], 429);
        }

        RateLimiter::hit($ipKey, self::DECAY_SECONDS);
        if ($codeKey) {
            RateLimiter::hit($codeKey, self::DECAY_SECONDS);
        }

        return $next($request);
    }

    private function rateLimitSecret(): string
    {
        $key = (string) config('app.key');

        return $key !== '' ? $key : static::FALLBACK_SECRET;
    }
}
