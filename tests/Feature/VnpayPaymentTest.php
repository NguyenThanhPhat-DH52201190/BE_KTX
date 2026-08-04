<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\RoomFeeBill;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class VnpayPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.vnpay.tmn_code'    => 'TESTTMN01',
            'services.vnpay.hash_secret' => 'TEST_SECRET_KEY_123',
        ]);
    }

    /**
     * TC06: a student can create a valid VNPay payment link for their own bill.
     */
    public function test_creates_valid_payment_link_for_own_bill(): void
    {
        $student = $this->student('DH20000001');
        $account = $this->accountFor($student);
        $bill = $this->bill($student->id, amount: 800000);

        $this->actingAs($account, 'sanctum');

        $response = $this->postJson('/api/payments/vnpay/create', [
            'source'  => 'room_fee',
            'bill_id' => $bill->id,
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['payment_url', 'transaction_code']);
        $this->assertStringContainsString('vnp_SecureHash=', $response->json('payment_url'));
        $this->assertSame($response->json('transaction_code'), $bill->fresh()->transaction_code);
    }

    /**
     * TC07: a student must not be able to create a payment link for a bill
     * that belongs to a different student (IDOR check).
     */
    public function test_rejects_payment_creation_for_another_students_bill(): void
    {
        $owner = $this->student('DH20000002');
        $attacker = $this->student('DH20000003');
        $attackerAccount = $this->accountFor($attacker);
        $bill = $this->bill($owner->id, amount: 800000);

        $this->actingAs($attackerAccount, 'sanctum');

        $response = $this->postJson('/api/payments/vnpay/create', [
            'source'  => 'room_fee',
            'bill_id' => $bill->id,
        ]);

        $response->assertStatus(403);
        $this->assertNull($bill->fresh()->transaction_code);
    }

    /**
     * TC08: a callback whose secure hash does not match the computed HMAC must
     * be rejected, and the bill must NOT be marked as paid.
     */
    public function test_rejects_callback_with_invalid_signature(): void
    {
        $student = $this->student('DH20000004');
        $account = $this->accountFor($student);
        $bill = $this->bill($student->id, amount: 800000, transactionCode: 'TXN0001');

        $this->actingAs($account, 'sanctum');

        $response = $this->postJson('/api/payments/vnpay/verify', [
            'vnp_TxnRef'            => 'TXN0001',
            'vnp_Amount'            => 80000000, // 800000 * 100
            'vnp_ResponseCode'      => '00',
            'vnp_TransactionStatus' => '00',
            'vnp_SecureHash'        => 'deliberately-invalid-hash',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $this->assertSame('unpaid', $bill->fresh()->status);
    }

    /**
     * TC09: a callback with a valid signature but a mismatched amount must be
     * rejected, and the bill must NOT be marked as paid.
     */
    public function test_rejects_callback_with_amount_mismatch(): void
    {
        $student = $this->student('DH20000005');
        $account = $this->accountFor($student);
        $bill = $this->bill($student->id, amount: 800000, transactionCode: 'TXN0002');

        $this->actingAs($account, 'sanctum');

        $params = [
            'vnp_TxnRef'            => 'TXN0002',
            'vnp_Amount'            => 50000000, // wrong: should be 80000000
            'vnp_ResponseCode'      => '00',
            'vnp_TransactionStatus' => '00',
        ];
        $params['vnp_SecureHash'] = $this->signParams($params, 'TEST_SECRET_KEY_123');

        $response = $this->postJson('/api/payments/vnpay/verify', $params);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $this->assertSame('unpaid', $bill->fresh()->status);
    }

    /**
     * Sanity check: a callback with a valid signature and matching amount
     * marks the bill as paid.
     */
    public function test_marks_bill_paid_on_valid_callback(): void
    {
        $student = $this->student('DH20000006');
        $account = $this->accountFor($student);
        $bill = $this->bill($student->id, amount: 800000, transactionCode: 'TXN0003');

        $this->actingAs($account, 'sanctum');

        $params = [
            'vnp_TxnRef'            => 'TXN0003',
            'vnp_Amount'            => 80000000,
            'vnp_ResponseCode'      => '00',
            'vnp_TransactionStatus' => '00',
        ];
        $params['vnp_SecureHash'] = $this->signParams($params, 'TEST_SECRET_KEY_123');

        $response = $this->postJson('/api/payments/vnpay/verify', $params);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $this->assertSame('paid', $bill->fresh()->status);
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function signParams(array $params, string $hashSecret): string
    {
        ksort($params);
        $segments = [];
        foreach ($params as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $segments[] = urlencode((string) $key) . '=' . urlencode((string) $value);
        }

        return hash_hmac('sha512', implode('&', $segments), $hashSecret);
    }

    private function student(string $code): Student
    {
        return Student::create([
            'student_code'      => $code,
            'full_name'         => 'Sinh viên ' . $code,
            'date_of_birth'     => '2004-01-01',
            'gender'            => 'male',
            'class_name'        => 'D22_TH00',
            'faculty'           => 'CNTT',
            'course_year'       => 'D22',
            'phone'             => '0900000000',
            'email'             => strtolower($code) . '@student.stu.edu.vn',
            'cccd'              => str_pad((string) crc32($code), 12, '0', STR_PAD_LEFT),
            'cccd_issued_date'  => '2022-01-01',
            'cccd_issued_place' => 'TP.HCM',
            'nationality'       => 'Việt Nam',
            'ethnicity'         => 'Kinh',
            'religion'          => 'Không',
            'permanent_address' => 'TP.HCM',
            'status'            => 'active',
        ]);
    }

    private function accountFor(Student $student): Account
    {
        return Account::create([
            'username'   => $student->student_code,
            'password'   => Hash::make('secret12345'),
            'role'       => 'student',
            'student_id' => $student->id,
            'is_active'  => true,
        ]);
    }

    private function bill(int $studentId, float $amount, ?string $transactionCode = null): RoomFeeBill
    {
        return RoomFeeBill::create([
            'student_id'       => $studentId,
            'month'            => 7,
            'year'             => 2026,
            'amount'           => $amount,
            'due_date'         => '2026-08-01',
            'status'           => 'unpaid',
            'transaction_code' => $transactionCode,
        ]);
    }
}
