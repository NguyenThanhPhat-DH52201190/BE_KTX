<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('static_pages', function (Blueprint $table) {
            // Danh sách thẻ (icon + tiêu đề + mô tả + link chi tiết) — dùng cho các trang dạng
            // lưới thẻ như "Quy định đăng ký"/"Cơ sở vật chất", khác với content (bài viết dài)
            // đã có sẵn cho trang Giới thiệu.
            $table->json('items')->nullable()->after('content');
        });
    }

    public function down(): void
    {
        Schema::table('static_pages', function (Blueprint $table) {
            $table->dropColumn('items');
        });
    }
};
