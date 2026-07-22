<?php

namespace App\Http\Controllers\Api;

use App\Helpers\StorageHelper;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StaticPageController extends Controller
{
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
            'content' => 'required|string',
            'image'   => 'nullable|file|image|max:5120', // tối đa 5 MB
        ]);

        $page = DB::table('static_pages')->where('slug', $slug)->first();

        if (!$page) {
            return response()->json(['message' => 'Không tìm thấy trang.'], 404);
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
            'images'     => json_encode(array_values($images)),
            'updated_at' => now(),
        ]);

        $updated = DB::table('static_pages')->where('slug', $slug)->first();

        return response()->json($this->format($updated));
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
            'stats'      => json_decode($page->stats, true),
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
