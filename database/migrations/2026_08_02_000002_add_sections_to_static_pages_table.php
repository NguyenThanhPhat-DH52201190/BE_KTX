<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('static_pages', function (Blueprint $table) {
            // Nội dung dạng khối cho các trang chi tiết (điều kiện đăng ký, hồ sơ cần chuẩn bị,
            // quy trình xét duyệt, quy định thanh toán, nội quy KTX...) — khác với `items` (danh
            // sách thẻ tóm tắt phẳng dùng ở trang chủ). Cấu trúc:
            // { hero_icon, groups: [{icon,title,description,items[]}], steps: [{icon,title}],
            //   highlights_title, highlights: [{icon,label}], notes_title, notes: string[] }
            $table->json('sections')->nullable()->after('items');
        });
    }

    public function down(): void
    {
        Schema::table('static_pages', function (Blueprint $table) {
            $table->dropColumn('sections');
        });
    }
};
