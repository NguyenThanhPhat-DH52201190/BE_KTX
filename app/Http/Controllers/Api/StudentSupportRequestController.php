<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProcessStudentSupportRequest;
use App\Http\Requests\StoreStudentSupportRequest;
use App\Http\Resources\StudentSupportRequestResource;
use App\Mail\GenericNotificationMail;
use App\Models\Account;
use App\Models\AdminNotification;
use App\Models\Student;
use App\Models\StudentSupportRequest;
use App\Services\StudentNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class StudentSupportRequestController extends Controller
{
    private const WITH_ALL = ['student'];

    public function studentIndex(Request $request): JsonResponse
    {
        $student = $this->resolveStudent($request);

        if (! $student) {
            return response()->json(['message' => 'Không tìm thấy sinh viên.'], 422);
        }

        $items = StudentSupportRequest::query()
            ->with(self::WITH_ALL)
            ->where('student_id', $student->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return response()->json(StudentSupportRequestResource::collection($items)->resolve());
    }

    public function studentShow(Request $request, int $id): JsonResponse
    {
        $student = $this->resolveStudent($request);

        if (! $student) {
            return response()->json(['message' => 'Không tìm thấy sinh viên.'], 422);
        }

        $supportRequest = StudentSupportRequest::query()
            ->with(self::WITH_ALL)
            ->where('student_id', $student->id)
            ->findOrFail($id);

        return response()->json((new StudentSupportRequestResource($supportRequest))->resolve());
    }

    public function store(StoreStudentSupportRequest $request): JsonResponse
    {
        $student = $this->resolveStudent($request);

        if (! $student) {
            return response()->json(['message' => 'Không tìm thấy sinh viên.'], 422);
        }

        $existingPending = StudentSupportRequest::query()
            ->where('student_id', $student->id)
            ->whereIn('status', ['pending', 'processing'])
            ->exists();

        if ($existingPending) {
            return response()->json([
                'message' => 'Bạn đang có yêu cầu hỗ trợ chờ xử lý. Vui lòng chờ admin phản hồi trước khi gửi yêu cầu mới.',
            ], 422);
        }

        $supportRequest = StudentSupportRequest::query()->create([
            'student_id'     => $student->id,
            'title'          => trim($request->string('title')->toString()),
            'content'        => trim($request->string('content')->toString()),
            'attachment_url' => $request->filled('attachment_url')
                ? trim($request->string('attachment_url')->toString())
                : null,
            'status'         => 'pending',
        ]);

        $this->notifyAdmin($student, $supportRequest);

        return response()->json(
            (new StudentSupportRequestResource($supportRequest->fresh(self::WITH_ALL)))->resolve(),
            201,
        );
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $query = StudentSupportRequest::query()
            ->with(self::WITH_ALL)
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->query('search'));
            $query->whereHas('student', function ($studentQuery) use ($search) {
                $studentQuery
                    ->where('student_code', 'like', "%{$search}%")
                    ->orWhere('full_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        return response()->json(StudentSupportRequestResource::collection($query->get())->resolve());
    }

    public function adminShow(int $id): JsonResponse
    {
        $supportRequest = StudentSupportRequest::query()
            ->with(self::WITH_ALL)
            ->findOrFail($id);

        return response()->json((new StudentSupportRequestResource($supportRequest))->resolve());
    }

    public function process(ProcessStudentSupportRequest $request, int $id): JsonResponse
    {
        return $this->transition($request, $id, 'processing');
    }

    public function approve(ProcessStudentSupportRequest $request, int $id): JsonResponse
    {
        return $this->transition($request, $id, 'approved');
    }

    public function reject(ProcessStudentSupportRequest $request, int $id): JsonResponse
    {
        return $this->transition($request, $id, 'rejected');
    }

    public function complete(ProcessStudentSupportRequest $request, int $id): JsonResponse
    {
        return $this->transition($request, $id, 'completed');
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'status'     => ['required', 'in:pending,processing,rejected,completed'],
            'admin_note' => ['nullable', 'string'],
        ]);

        $supportRequest = StudentSupportRequest::with('student')->findOrFail($id);
        $previousStatus = $supportRequest->status;

        $supportRequest->update([
            'status'     => $validated['status'],
            'admin_note' => array_key_exists('admin_note', $validated)
                ? $validated['admin_note']
                : $supportRequest->admin_note,
        ]);

        if ($validated['status'] !== $previousStatus) {
            $this->notifyStudentStatusChange($supportRequest);
        }

        return response()->json(
            (new StudentSupportRequestResource($supportRequest->fresh(self::WITH_ALL)))->resolve(),
        );
    }

    // ── Shared helpers ─────────────────────────────────────────────────────────

    private function transition(ProcessStudentSupportRequest $request, int $id, string $status): JsonResponse
    {
        $supportRequest = StudentSupportRequest::with('student')->findOrFail($id);
        $previousStatus = $supportRequest->status;

        $supportRequest->update([
            'status'     => $status,
            'admin_note' => $request->has('admin_note') ? $request->input('admin_note') : $supportRequest->admin_note,
        ]);

        if ($status !== $previousStatus) {
            $this->notifyStudentStatusChange($supportRequest);
        }

        return response()->json(
            (new StudentSupportRequestResource($supportRequest->fresh(self::WITH_ALL)))->resolve(),
        );
    }

    /** Báo cho sinh viên (chuông + email) mỗi khi trạng thái yêu cầu hỗ trợ đổi — trước đây
     *  chỉ có notifyAdmin() (báo admin lúc sinh viên GỬI yêu cầu), chiều ngược lại (admin xử
     *  lý xong báo cho sinh viên) chưa từng được làm, sinh viên không biết yêu cầu đã được xử
     *  lý trừ khi tự vào xem lại trang "Yêu cầu hỗ trợ". */
    private function notifyStudentStatusChange(StudentSupportRequest $supportRequest): void
    {
        $student = $supportRequest->student;
        if (!$student) {
            return;
        }

        $statusMessage = match ($supportRequest->status) {
            'processing' => "Yêu cầu hỗ trợ \"{$supportRequest->title}\" của bạn đang được xử lý.",
            'rejected'   => "Yêu cầu hỗ trợ \"{$supportRequest->title}\" của bạn đã bị từ chối."
                . ($supportRequest->admin_note ? " Lý do: {$supportRequest->admin_note}" : ''),
            'completed'  => "Yêu cầu hỗ trợ \"{$supportRequest->title}\" của bạn đã được xử lý xong.",
            default      => "Yêu cầu hỗ trợ \"{$supportRequest->title}\" của bạn đã được duyệt, đang chờ xử lý.",
        };

        $titleMessage = match ($supportRequest->status) {
            'processing' => 'Yêu cầu hỗ trợ đang được xử lý',
            'rejected'   => 'Yêu cầu hỗ trợ bị từ chối',
            'completed'  => 'Yêu cầu hỗ trợ đã hoàn tất',
            default      => 'Yêu cầu hỗ trợ đã được duyệt',
        };

        app(StudentNotificationService::class)->notifyStudent(
            $student,
            $titleMessage,
            $statusMessage,
            'support_request_status',
            $supportRequest->id,
            queue: true,
        );
    }

    private function notifyAdmin(Student $student, StudentSupportRequest $req): void
    {
        try {
            $title   = 'Yêu cầu hỗ trợ mới';
            $content = "Sinh viên {$student->full_name} ({$student->student_code}) vừa gửi yêu cầu hỗ trợ: {$req->title}.";

            AdminNotification::create([
                'title'      => $title,
                'content'    => $content,
                'type'       => 'support_request_new',
                'related_id' => $req->id,
                'created_at' => now(),
            ]);
        } catch (\Exception) {}

        try {
            $adminEmails = Account::where('role', 'admin')
                ->with('student')
                ->get()
                ->map(fn ($acc) => $acc->student?->email)
                ->push(config('auth.admin_login_email'))
                ->filter()
                ->unique()
                ->values();

            if ($adminEmails->isEmpty()) {
                return;
            }

            $subject   = '[KTX STU] Yêu cầu hỗ trợ mới';
            $body      = "
                <div style='font-family:sans-serif;max-width:600px;margin:auto;padding:24px;background:#f8fbff;border-radius:12px;'>
                    <h2 style='color:#1a2d52;margin-bottom:8px;'>Yêu cầu hỗ trợ mới</h2>
                    <p style='color:#667ca8;margin-bottom:20px;'>Có một yêu cầu hỗ trợ mới cần xử lý trên hệ thống KTX.</p>
                    <table style='width:100%;border-collapse:collapse;background:#fff;border-radius:8px;overflow:hidden;'>
                        <tr style='background:#eef4ff;'>
                            <td style='padding:10px 16px;font-weight:bold;color:#244cb8;width:40%;'>Sinh viên</td>
                            <td style='padding:10px 16px;color:#1f3152;'>{$student->full_name} ({$student->student_code})</td>
                        </tr>
                        <tr>
                            <td style='padding:10px 16px;font-weight:bold;color:#244cb8;border-top:1px solid #e8eef8;'>Email</td>
                            <td style='padding:10px 16px;color:#1f3152;border-top:1px solid #e8eef8;'>{$student->email}</td>
                        </tr>
                        <tr style='background:#eef4ff;'>
                            <td style='padding:10px 16px;font-weight:bold;color:#244cb8;border-top:1px solid #e8eef8;'>Tiêu đề</td>
                            <td style='padding:10px 16px;color:#1f3152;border-top:1px solid #e8eef8;'>{$req->title}</td>
                        </tr>
                        <tr style='background:#eef4ff;'>
                            <td style='padding:10px 16px;font-weight:bold;color:#244cb8;border-top:1px solid #e8eef8;vertical-align:top;'>Nội dung</td>
                            <td style='padding:10px 16px;color:#1f3152;border-top:1px solid #e8eef8;'>{$req->content}</td>
                        </tr>
                    </table>
                    <p style='margin-top:20px;color:#7c8fb5;font-size:13px;'>Vui lòng đăng nhập hệ thống để xem và xử lý yêu cầu.</p>
                </div>
            ";

            // Queue để không chặn request chờ gửi lần lượt tới toàn bộ admin.
            foreach ($adminEmails as $email) {
                try {
                    Mail::to($email)->queue(new GenericNotificationMail($subject, $body));
                } catch (\Exception $e) {
                    Log::error('Gửi email thông báo thất bại', [
                        'type'                => 'support_request_new',
                        'support_request_id'  => $req->id,
                        'email'               => $email,
                        'error'               => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Exception) {}
    }

    // Lấy sinh viên từ tài khoản đang đăng nhập (route đã bảo vệ auth:sanctum + role:student),
    // không nhận student_id/email từ client — tránh 1 sinh viên xem/gửi yêu cầu thay sinh viên khác.
    private function resolveStudent(Request $request): ?Student
    {
        $account = $request->user();

        return $account?->student_id ? Student::query()->find($account->student_id) : null;
    }
}
