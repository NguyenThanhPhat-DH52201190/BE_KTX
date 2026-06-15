<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bảng danh mục 34 đơn vị hành chính cấp tỉnh mới
     * (theo Nghị quyết 202/2025/QH15, hiệu lực 12/6/2025).
     */
    public function up(): void
    {
        if (Schema::hasTable('provinces')) {
            return;
        }

        Schema::create('provinces', function (Blueprint $table) {
            $table->string('code', 10)->primary();
            $table->string('name', 100);
            // Vùng miền: "Bắc Bộ", "Trung Bộ", "Nam Bộ", "Tây Nguyên".
            $table->string('region', 50)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('region');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provinces');
    }
};
