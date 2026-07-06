<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ElectricityBill;
use App\Models\Notification;
use App\Models\Occupancy;
use App\Models\RoomFeeBill;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

class VnpayPaymentController extends Controller
{
    public function create(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'source' => ['required', 'string', 'in:room_fee,electricity'],
            'bill_id' => ['required', 'integer'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $bill = $this->findBill((string) $request->input('source'), (int) $request->input('bill_id'));

        if (!$bill) {
            return response()->json(['message' => 'Khong tim thay hoa don.'], 404);
        }

        // Danh tính lấy từ $request->user() (route đã bảo vệ auth:sanctum + role:student),
        // không nhận email từ client — tránh 1 sinh viên tạo link thanh toán thay người khác.
        $account = $request->user();
        if ((int) $bill->student_id !== (int) $account->student_id) {
            return response()->json(['message' => 'Hoa don khong thuoc sinh vien nay.'], 403);
        }

        if (($bill->status ?? 'unpaid') === 'paid') {
            return response()->json(['message' => 'Hoa don da duoc thanh toan.'], 422);
        }

        if ((float) $bill->amount <= 0) {
            return response()->json(['message' => 'So tien hoa don khong hop le.'], 422);
        }

        $tmnCode = $this->vnpayConfig('VNPAY_TMN_CODE', 'tmn_code');
        $hashSecret = $this->vnpayConfig('VNPAY_HASH_SECRET', 'hash_secret');
        $paymentUrl = $this->vnpayConfig('VNPAY_PAYMENT_URL', 'payment_url');
        $returnUrl = $this->vnpayConfig('VNPAY_RETURN_URL', 'return_url');

        if (!$tmnCode || !$hashSecret || !$paymentUrl || !$returnUrl) {
            return response()->json(['message' => 'Chua cau hinh thong tin VNPay.'], 500);
        }

        $source = (string) $request->input('source');
        $vnpayTxnRef = (string) time() . $bill->id . ($source === 'electricity' ? '2' : '1');

        $bill->update([
            'payment_method' => 'VNPay',
            'transaction_code' => $vnpayTxnRef,
        ]);

        $createdAt = Carbon::now('Asia/Ho_Chi_Minh');
        $params = [
            'vnp_Version' => '2.1.0',
            'vnp_Command' => 'pay',
            'vnp_TmnCode' => $tmnCode,
            'vnp_Amount' => (int) round(((float) $bill->amount) * 100),
            'vnp_CurrCode' => 'VND',
            'vnp_TxnRef' => $vnpayTxnRef,
            'vnp_OrderInfo' => 'Thanh toan hoa don KTX ' . $vnpayTxnRef,
            'vnp_OrderType' => 'billpayment',
            'vnp_Locale' => 'vn',
            'vnp_ReturnUrl' => $returnUrl,
            'vnp_IpAddr' => $this->clientIp($request),
            'vnp_CreateDate' => $createdAt->format('YmdHis'),
            'vnp_ExpireDate' => $createdAt->copy()->addMinutes(15)->format('YmdHis'),
        ];

        return response()->json([
            'payment_url' => $paymentUrl . '?' . $this->buildSignedQuery($params, $hashSecret),
            'transaction_code' => $vnpayTxnRef,
        ]);
    }

    public function handleReturn(Request $request): RedirectResponse
    {
        $frontendUrl = rtrim((string) config('services.vnpay.frontend_return_url'), '/');
        $isSuccess = $this->verifyAndUpdateBill($request->query())['success'];

        return redirect()->away($frontendUrl . '?vnpay=' . ($isSuccess ? 'success' : 'failed'));
    }

    public function verify(Request $request): JsonResponse
    {
        $result = $this->verifyAndUpdateBill($request->all());

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
        ], $result['status']);
    }

    private function findBill(string $source, int $billId): RoomFeeBill|ElectricityBill|null
    {
        if ($source === 'electricity') {
            return ElectricityBill::query()->with('student')->find($billId);
        }

        return RoomFeeBill::query()->with('student')->find($billId);
    }

    private function findBillByTransactionCode(string $transactionCode): RoomFeeBill|ElectricityBill|null
    {
        return RoomFeeBill::query()->where('transaction_code', $transactionCode)->first()
            ?? ElectricityBill::query()->where('transaction_code', $transactionCode)->first();
    }

    private function verifyAndUpdateBill(array $params): array
    {
        $hashSecret = $this->vnpayConfig('VNPAY_HASH_SECRET', 'hash_secret');
        $secureHash = (string) ($params['vnp_SecureHash'] ?? '');
        $transactionCode = (string) ($params['vnp_TxnRef'] ?? '');
        $bill = $this->findBillByTransactionCode($transactionCode);
        $isValidSignature = $hashSecret && $secureHash !== '' && hash_equals($secureHash, $this->makeHash($params, $hashSecret));
        $amountMatches = $bill && (int) round(((float) $bill->amount) * 100) === (int) ($params['vnp_Amount'] ?? 0);
        $isSuccess = $isValidSignature
            && $amountMatches
            && ($params['vnp_ResponseCode'] ?? '') === '00'
            && ($params['vnp_TransactionStatus'] ?? '') === '00';

        if (!$isValidSignature) {
            return ['success' => false, 'message' => 'Chu ky VNPay khong hop le.', 'status' => 422];
        }

        if (!$bill) {
            return ['success' => false, 'message' => 'Khong tim thay hoa don.', 'status' => 404];
        }

        if (!$amountMatches) {
            return ['success' => false, 'message' => 'So tien thanh toan khong khop hoa don.', 'status' => 422];
        }

        if (!$isSuccess) {
            return ['success' => false, 'message' => 'Giao dich VNPay khong thanh cong.', 'status' => 200];
        }

        if (($bill->status ?? 'unpaid') !== 'paid') {
            $bill->update([
                'status'           => 'paid',
                'payment_method'   => 'VNPay',
                'paid_at'          => Carbon::now(),
            ]);

            // Nếu là hóa đơn phòng và occupancy đang PENDING_PAYMENT → kích hoạt lưu trú
            if ($bill instanceof RoomFeeBill && $bill->occupancy_id) {
                $occupancy = Occupancy::find($bill->occupancy_id);
                if ($occupancy && $occupancy->status === 'PENDING_PAYMENT') {
                    $occupancy->status = 'ACTIVE';
                    $occupancy->save();

                    // Thông báo sinh viên
                    $room  = $occupancy->room;
                    $floor = $room?->floor;
                    $bed   = $occupancy->bed;
                    $roomCode = ($floor?->building_code ?? '') . ($room?->room_number ?? '');
                    $bedNo    = $bed?->bed_number ?? '';

                    Notification::create([
                        'student_id' => $occupancy->student_id,
                        'title'      => 'Thanh toán thành công!',
                        'content'    => "Bạn đã chính thức lưu trú tại phòng {$roomCode}" . ($bedNo ? " giường #{$bedNo}" : '') . '. Chào mừng bạn đến với KTX!',
                        'type'       => 'payment_success',
                    ]);
                }
            }
        }

        return ['success' => true, 'message' => 'Thanh toan VNPay thanh cong.', 'status' => 200];
    }

    private function buildSignedQuery(array $params, string $hashSecret): string
    {
        ksort($params);
        $hashData = '';
        $query = '';
        $index = 0;

        foreach ($params as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $encoded = urlencode((string) $key) . '=' . urlencode((string) $value);
            $hashData .= ($index === 0 ? '' : '&') . $encoded;
            $query .= $encoded . '&';
            $index++;
        }

        $secureHash = hash_hmac('sha512', $hashData, $hashSecret);

        return $query . 'vnp_SecureHash=' . $secureHash;
    }

    private function makeHash(array $params, string $hashSecret): string
    {
        unset($params['vnp_SecureHash'], $params['vnp_SecureHashType']);
        ksort($params);

        return hash_hmac('sha512', $this->buildHashData($params), $hashSecret);
    }

    private function buildHashData(array $params): string
    {
        $segments = [];
        foreach ($params as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $segments[] = urlencode((string) $key) . '=' . urlencode((string) $value);
        }

        return implode('&', $segments);
    }

    private function vnpayConfig(string $envKey, string $configKey): string
    {
        return trim((string) (env($envKey) ?: config('services.vnpay.' . $configKey, '')));
    }

    private function clientIp(Request $request): string
    {
        $ip = (string) $request->ip();

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $ip : '127.0.0.1';
    }
}
