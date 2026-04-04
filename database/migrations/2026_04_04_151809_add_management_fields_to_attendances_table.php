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
        Schema::table('attendances', function (Blueprint $table): void {
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('corrected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('worked_minutes')->default(0);
            $table->unsignedInteger('late_minutes')->default(0);
            $table->unsignedInteger('early_leave_minutes')->default(0);
            $table->string('source', 30)->default('manual');
            $table->text('notes')->nullable();
            $table->text('correction_reason')->nullable();
            $table->string('correction_status', 20)->default('none');

            $table->index(['status', 'attendance_date']);
            $table->index('correction_status');
            $table->index('created_by');
            $table->index('updated_by');
            $table->index('corrected_by');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE attendances DROP CONSTRAINT IF EXISTS attendances_status_check');
            DB::statement(
                "ALTER TABLE attendances ADD CONSTRAINT attendances_status_check CHECK (status IN ('checked_in', 'present', 'late', 'absent', 'corrected'))"
            );
            DB::statement(
                "ALTER TABLE attendances ADD CONSTRAINT attendances_source_check CHECK (source IN ('self_service', 'scan', 'manual', 'correction'))"
            );
            DB::statement(
                "ALTER TABLE attendances ADD CONSTRAINT attendances_correction_status_check CHECK (correction_status IN ('none', 'pending', 'approved', 'rejected'))"
            );
            DB::statement(
                'ALTER TABLE attendances ADD CONSTRAINT attendances_minutes_check CHECK (worked_minutes >= 0 AND late_minutes >= 0 AND early_leave_minutes >= 0)'
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE attendances DROP CONSTRAINT IF EXISTS attendances_minutes_check');
            DB::statement('ALTER TABLE attendances DROP CONSTRAINT IF EXISTS attendances_correction_status_check');
            DB::statement('ALTER TABLE attendances DROP CONSTRAINT IF EXISTS attendances_source_check');
            DB::statement('ALTER TABLE attendances DROP CONSTRAINT IF EXISTS attendances_status_check');
            DB::statement(
                "ALTER TABLE attendances ADD CONSTRAINT attendances_status_check CHECK (status IN ('present', 'late', 'absent'))"
            );
        }

        Schema::table('attendances', function (Blueprint $table): void {
            $table->dropIndex(['status', 'attendance_date']);
            $table->dropIndex(['correction_status']);
            $table->dropIndex(['created_by']);
            $table->dropIndex(['updated_by']);
            $table->dropIndex(['corrected_by']);

            $table->dropConstrainedForeignId('corrected_by');
            $table->dropConstrainedForeignId('updated_by');
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn([
                'worked_minutes',
                'late_minutes',
                'early_leave_minutes',
                'source',
                'notes',
                'correction_reason',
                'correction_status',
            ]);
        });
    }
};
