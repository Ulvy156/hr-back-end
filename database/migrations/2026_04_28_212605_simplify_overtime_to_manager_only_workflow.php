<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('overtime_requests')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE overtime_requests DROP CONSTRAINT IF EXISTS overtime_requests_status_check');
            DB::statement('ALTER TABLE overtime_requests DROP CONSTRAINT IF EXISTS overtime_requests_stage_check');
            DB::statement('ALTER TABLE overtime_requests DROP CONSTRAINT IF EXISTS overtime_requests_type_check');
            DB::statement('ALTER TABLE overtime_requests DROP CONSTRAINT IF EXISTS overtime_requests_minutes_check');
        }

        DB::table('overtime_requests')
            ->whereIn('status', ['manager_approved', 'hr_approved'])
            ->update([
                'status' => 'approved',
                'approval_stage' => 'completed',
            ]);

        DB::table('overtime_requests')
            ->where('status', 'pending')
            ->update(['approval_stage' => 'manager_review']);

        DB::table('overtime_requests')
            ->whereIn('status', ['rejected', 'cancelled'])
            ->update(['approval_stage' => 'completed']);

        if (Schema::hasColumn('overtime_requests', 'hr_approved_by')) {
            Schema::table('overtime_requests', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('hr_approved_by');
            });
        }

        if (Schema::hasColumn('overtime_requests', 'hr_approved_at')) {
            Schema::table('overtime_requests', function (Blueprint $table): void {
                $table->dropColumn('hr_approved_at');
            });
        }

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE overtime_requests ADD CONSTRAINT overtime_requests_status_check CHECK (status IN ('pending', 'approved', 'rejected', 'cancelled'))"
            );
            DB::statement(
                "ALTER TABLE overtime_requests ADD CONSTRAINT overtime_requests_stage_check CHECK (approval_stage IN ('manager_review', 'completed'))"
            );
            DB::statement(
                "ALTER TABLE overtime_requests ADD CONSTRAINT overtime_requests_type_check CHECK (overtime_type IN ('normal', 'weekend', 'holiday'))"
            );
            DB::statement(
                'ALTER TABLE overtime_requests ADD CONSTRAINT overtime_requests_minutes_check CHECK (minutes >= 0 AND end_time > start_time)'
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('overtime_requests')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE overtime_requests DROP CONSTRAINT IF EXISTS overtime_requests_status_check');
            DB::statement('ALTER TABLE overtime_requests DROP CONSTRAINT IF EXISTS overtime_requests_stage_check');
            DB::statement('ALTER TABLE overtime_requests DROP CONSTRAINT IF EXISTS overtime_requests_type_check');
            DB::statement('ALTER TABLE overtime_requests DROP CONSTRAINT IF EXISTS overtime_requests_minutes_check');
        }

        if (! Schema::hasColumn('overtime_requests', 'hr_approved_by')) {
            Schema::table('overtime_requests', function (Blueprint $table): void {
                $table->foreignId('hr_approved_by')->nullable()->constrained('employees')->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('overtime_requests', 'hr_approved_at')) {
            Schema::table('overtime_requests', function (Blueprint $table): void {
                $table->timestamp('hr_approved_at')->nullable();
            });
        }

        DB::table('overtime_requests')
            ->where('status', 'approved')
            ->update([
                'status' => 'manager_approved',
                'approval_stage' => 'hr_review',
            ]);

        DB::table('overtime_requests')
            ->where('status', 'pending')
            ->update(['approval_stage' => 'manager_review']);

        DB::table('overtime_requests')
            ->whereIn('status', ['rejected', 'cancelled'])
            ->update(['approval_stage' => 'manager_review']);

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE overtime_requests ADD CONSTRAINT overtime_requests_status_check CHECK (status IN ('pending', 'manager_approved', 'hr_approved', 'rejected', 'cancelled'))"
            );
            DB::statement(
                "ALTER TABLE overtime_requests ADD CONSTRAINT overtime_requests_stage_check CHECK (approval_stage IN ('manager_review', 'hr_review', 'completed'))"
            );
            DB::statement(
                "ALTER TABLE overtime_requests ADD CONSTRAINT overtime_requests_type_check CHECK (overtime_type IN ('normal', 'weekend', 'holiday'))"
            );
            DB::statement(
                'ALTER TABLE overtime_requests ADD CONSTRAINT overtime_requests_minutes_check CHECK (minutes >= 0 AND end_time > start_time)'
            );
        }
    }
};
