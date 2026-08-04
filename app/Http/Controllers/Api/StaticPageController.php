<?php

namespace App\Http\Controllers\Api;

use App\Helpers\StorageHelper;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StaticPageController extends Controller
{
    // Danh sách icon được phép chọn cho các trang dạng lưới thẻ (VD: Quy định đăng ký, Cơ sở
    // vật chất) — chỉ nhận đúng tên trong danh sách này, khớp với bộ icon FE đã import sẵn
    // (Lucide), tránh admin gõ tay tên icon không tồn tại khiến giao diện lỗi.
    public const ALLOWED_ITEM_ICONS = [
        'BadgeCheck', 'FileText', 'ClipboardCheck', 'CreditCard', 'Home', 'Utensils', 'Car',
        'BookOpen', 'Users', 'Camera', 'Flame', 'ShieldCheck', 'Wifi', 'Droplets', 'Building2',
        'BedDouble', 'AlarmClock', 'PhoneCall',
    ];

    // Ánh xạ đường dẫn (detail_path trên thẻ) → slug của trang chi tiết tương ứng — dùng để tự
    // đồng bộ tiêu đề: đổi tên thẻ ở "Quy định đăng ký" thì tiêu đề trang chi tiết liên kết
    // cũng tự đổi theo (thẻ là nguồn dữ liệu chính, trang chi tiết chỉ "ăn theo").
    public const DETAIL_PATH_TO_SLUG = [
        '/dieu-kien-noi-tru'    => 'ct-dieu-kien-dang-ky',
        '/ho-so-can-chuan-bi'   => 'ct-ho-so-can-chuan-bi',
        '/quy-trinh-xet-duyet'  => 'ct-quy-trinh-xet-duyet',
        '/quy-dinh-thanh-toan'  => 'ct-quy-dinh-thanh-toan',
        '/noi-quy-ktx'          => 'ct-noi-quy-ktx',
    ];

    public function show(string $slug)
    {
        $page = DB::table('static_pages')
            ->where('slug', $slug)
            ->where('is_active', true)
            ->first();

        if (!$page) {
            return response()->json(['message' => 'Không tìm thấy trang.'], 404);
        }

        return response()->json($this->format($page));
    }

    // POST (multipart/form-data) — hỗ trợ upload ảnh cùng lúc với text fields
    public function update(Request $request, string $slug)
    {
        $request->validate([
            'title'   => 'required|string|max:255',
            'summary' => 'nullable|string',
            // content chỉ bắt buộc cho trang dạng bài viết dài (VD: Giới thiệu) — trang dạng
            // lưới thẻ (items) không cần nội dung dài, để trống được.
            'content' => 'nullable|string',
            'items'    => 'nullable|string', // JSON-encoded array, tự giải mã + validate bên dưới
            'sections' => 'nullable|string', // JSON-encoded object, tự giải mã + validate bên dưới
            'image'    => 'nullable|file|image|max:5120', // tối đa 5 MB
        ]);

        $page = DB::table('static_pages')->where('slug', $slug)->first();

        if (!$page) {
            return response()->json(['message' => 'Không tìm thấy trang.'], 404);
        }

        $items = null;
        if ($request->filled('items')) {
            $decoded = json_decode((string) $request->input('items'), true);
            if (!is_array($decoded)) {
                return response()->json(['message' => 'Dữ liệu danh sách thẻ (items) không hợp lệ.'], 422);
            }

            foreach ($decoded as $index => $item) {
                if (!is_array($item) || empty($item['title']) || empty($item['description'])) {
                    return response()->json(['message' => "Thẻ thứ " . ($index + 1) . " thiếu tiêu đề hoặc mô tả."], 422);
                }
                if (empty($item['icon']) || !in_array($item['icon'], self::ALLOWED_ITEM_ICONS, true)) {
                    return response()->json(['message' => "Thẻ thứ " . ($index + 1) . " chọn icon không hợp lệ."], 422);
                }
            }

            $items = array_map(fn (array $item) => [
                'icon'        => $item['icon'],
                'title'       => (string) $item['title'],
                'description' => (string) $item['description'],
                'detail_path' => $item['detail_path'] ?? null,
            ], $decoded);
        }

        $sections = null;
        if ($request->filled('sections')) {
            $decodedSections = json_decode((string) $request->input('sections'), true);
            if (!is_array($decodedSections)) {
                return response()->json(['message' => 'Dữ liệu nội dung trang (sections) không hợp lệ.'], 422);
            }

            $error = $this->validateSections($decodedSections);
            if ($error) {
                return response()->json(['message' => $error], 422);
            }

            $sections = $decodedSections;
        }

        $images = json_decode($page->images, true) ?? [];

        // Xử lý upload ảnh nếu có
        if ($request->hasFile('image')) {
            $file     = $request->file('image');
            $filename = 'page_' . $slug . '_' . time() . '.' . $file->getClientOriginalExtension();
            $dir      = 'pages/' . $slug;

            if (StorageHelper::isRailwayWithVolume()) {
                $volumePath = env('RAILWAY_VOLUME_PATH', '/data/storage');
                $fullDir    = $volumePath . '/' . $dir;
                if (!file_exists($fullDir)) {
                    mkdir($fullDir, 0755, true);
                }
                $file->move($fullDir, $filename);
                $storedPath = $dir . '/' . $filename;
            } else {
                $storedPath = $file->store($dir, 'public');
            }

            // Đặt ảnh mới ở vị trí đầu (ảnh đại diện)
            $images[0] = $storedPath;
        }

        DB::table('static_pages')->where('slug', $slug)->update([
            'title'      => $request->input('title'),
            'summary'    => $request->input('summary'),
            'content'    => $request->input('content'),
            'items'      => $items !== null ? json_encode($items) : $page->items,
            'sections'   => $sections !== null ? json_encode($sections) : $page->sections,
            'images'     => json_encode(array_values($images)),
            'updated_at' => now(),
        ]);

        if ($items !== null) {
            $this->syncDetailPageTitles($items);
        }

        $updated = DB::table('static_pages')->where('slug', $slug)->first();

        return response()->json($this->format($updated));
    }

    /**
     * Thẻ là nguồn dữ liệu chính cho tiêu đề — sau khi lưu danh sách thẻ, đẩy luôn tiêu đề của
     * từng thẻ có liên kết (detail_path) sang đúng trang chi tiết tương ứng, để 2 nơi không bị
     * lệch tên khi chỉ sửa 1 chỗ (báo cáo 02/08).
     */
    private function syncDetailPageTitles(array $items): void
    {
        foreach ($items as $item) {
            $path = $item['detail_path'] ?? null;
            if (!$path || !isset(self::DETAIL_PATH_TO_SLUG[$path])) {
                continue;
            }

            DB::table('static_pages')
                ->where('slug', self::DETAIL_PATH_TO_SLUG[$path])
                ->where('title', '!=', $item['title'])
                ->update(['title' => $item['title'], 'updated_at' => now()]);
        }
    }

    /** Trả về thông báo lỗi (string) nếu dữ liệu sections không hợp lệ, null nếu hợp lệ. */
    private function validateSections(array $sections): ?string
    {
        if (isset($sections['hero_icon']) && !in_array($sections['hero_icon'], self::ALLOWED_ITEM_ICONS, true)) {
            return 'Icon tiêu đề trang không hợp lệ.';
        }

        foreach (($sections['groups'] ?? []) as $index => $group) {
            if (!is_array($group) || empty($group['title'])) {
                return "Nhóm thứ " . ($index + 1) . " thiếu tiêu đề.";
            }
            if (empty($group['icon']) || !in_array($group['icon'], self::ALLOWED_ITEM_ICONS, true)) {
                return "Nhóm thứ " . ($index + 1) . " chọn icon không hợp lệ.";
            }
        }

        foreach (($sections['steps'] ?? []) as $index => $step) {
            if (!is_array($step) || empty($step['title'])) {
                return "Bước thứ " . ($index + 1) . " thiếu tiêu đề.";
            }
            if (empty($step['icon']) || !in_array($step['icon'], self::ALLOWED_ITEM_ICONS, true)) {
                return "Bước thứ " . ($index + 1) . " chọn icon không hợp lệ.";
            }
        }

        foreach (($sections['highlights'] ?? []) as $index => $highlight) {
            if (!is_array($highlight) || empty($highlight['label'])) {
                return "Mục nổi bật thứ " . ($index + 1) . " thiếu nội dung.";
            }
            if (empty($highlight['icon']) || !in_array($highlight['icon'], self::ALLOWED_ITEM_ICONS, true)) {
                return "Mục nổi bật thứ " . ($index + 1) . " chọn icon không hợp lệ.";
            }
        }

        return null;
    }

    private function format(object $page): array
    {
        $images = json_decode($page->images, true) ?? [];

        // Resolve từng path thành URL đầy đủ
        $resolvedImages = array_map(fn($path) => $this->resolveImageUrl($path), $images);

        return [
            'id'         => $page->id,
            'slug'       => $page->slug,
            'title'      => $page->title,
            'summary'    => $page->summary,
            'content'    => $page->content,
            'items'      => json_decode($page->items ?? '', true) ?? [],
            'sections'   => json_decode($page->sections ?? '', true),
            'stats'      => json_decode($page->stats ?? '', true),
            'images'     => $resolvedImages,
            'updated_at' => $page->updated_at,
        ];
    }

    private function resolveImageUrl(string $path): string
    {
        // Đã là URL đầy đủ → trả nguyên
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        // Một số bản ghi (vd. Registration convert từ hồ sơ giữ chỗ tân sinh viên) đã có sẵn
        // prefix storage trong path lưu — phải strip trước, tránh double-prefix khiến ảnh 404.
        $cleanPath = preg_replace('#^/?(api/)?storage/#', '', ltrim($path, '/'));
        $isProduction = app()->environment('production') || env('RAILWAY_ENVIRONMENT') === 'production';

        return $isProduction
            ? url('/api/storage/' . $cleanPath)
            : url('/storage/' . $cleanPath);
    }
}
