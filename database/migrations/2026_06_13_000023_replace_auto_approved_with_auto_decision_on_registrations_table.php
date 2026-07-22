<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * New flow: the system only SUGGESTS a decision; admin makes the final call.
     * Replace auto_approved (bool) + auto_approval_reason with a single
     * auto_decision enum label. auto_approval_reason is unused in code, so it is
     * dropped rather than renamed.
     */
    public function up(): void
    {
        if (! Schema::hasTable('registrations')) {
            return;
        }

        Schema::table('registrations', function (Blueprint $table) {
            if (! Schema::hasColumn('registrations', 'auto_decision')) {
                $table->enum('auto_decision', ['approve', 'reject', 'review'])
                    ->nullable()
                    ->after('status');
            }
        });

        if (Schema::hasColumn('registrations', 'auto_approved')) {
            DB::table('registrations')->where('auto_approved', 1)->update(['auto_decision' => 'approve']);
        }

        Schema::table('registrations', function (Blueprint $table) {
            if (Schema::hasColumn('registrations', 'auto_approval_reason')) {
                $table->dropColumn('auto_approval_reason');
            }
            if (Schema::hasColumn('registrations', 'auto_approved')) {
                $table->dropColumn('auto_approved');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('registrations')) {
            return;
        }

        Schema::table('registrations', function (Blueprint $table) {
            if (! Schema::hasColumn('registrations', 'auto_approved')) {
                $table->boolean('auto_approved')->default(false)->after('status');
            }
            if (! Schema::hasColumn('registrations', 'auto_approval_reason')) {
                $table->string('auto_approval_reason')->nullable()->after('auto_approved');
            }
        });

        if (Schema::hasColumn('registrations', 'auto_decision')) {
            DB::table('registrations')->where('auto_decision', 'approve')->update(['auto_approved' => 1]);

            Schema::table('registrations', function (Blueprint $table) {
                $table->dropColumn('auto_decision');
            });
        }
    }
};
