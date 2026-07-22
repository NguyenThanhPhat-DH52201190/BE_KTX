<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // No-op: original migration incorrectly caught admin-assigned occupancies.
        // Those occupancies (24, 27, 43) were reverted to ACTIVE manually.
        // The selectBed() flow now correctly sets PENDING_PAYMENT, so no data fix needed.
    }

    public function down(): void
    {
        // Nothing to undo.
    }
};
