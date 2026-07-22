<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('rooms', 'price_per_quarter') && !Schema::hasColumn('rooms', 'price_per_month')) {
            Schema::table('rooms', function ($table) {
                $table->renameColumn('price_per_quarter', 'price_per_month');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('rooms', 'price_per_month') && !Schema::hasColumn('rooms', 'price_per_quarter')) {
            Schema::table('rooms', function ($table) {
                $table->renameColumn('price_per_month', 'price_per_quarter');
            });
        }
    }
};
