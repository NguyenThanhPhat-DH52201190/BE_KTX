<?php

namespace App\Http\Middleware;

class ThrottleDormReservationLookup extends ThrottleAdmissionCandidateVerify
{
    protected const MAX_PER_IP = 30;
    protected const MAX_PER_CODE = 10;
    protected const INPUT_FIELD = 'reservation_code';
    protected const KEY_PREFIX = 'dorm-reservation-lookup';
    protected const FALLBACK_SECRET = 'dorm-reservation-lookup';
    protected const TOO_MANY_ATTEMPTS_MESSAGE = 'Bạn đã tra cứu quá nhiều lần. Vui lòng thử lại sau.';
}
