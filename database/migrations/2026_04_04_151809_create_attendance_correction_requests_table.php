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
        Schema::create('attendance_correction_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('attendance_id')->nullable()->constrained('attendances')->nullOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->timestamp('requested_check_in_time')->nullable();
            $table->timestamp('requested_check_out_time')->nullable();
            $table->text('reason');
            $table->string('status', 20)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index(['attendance_id']);
            $table->index(['status', 'created_at']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE attendance_correction_requests ADD CONSTRAINT attendance_correction_requests_status_check CHECK (status IN ('pending', 'approved', 'rejected'))"
            );
            DB::statement(
                'ALTER TABLE attendance_correction_requests ADD CONSTRAINT attendance_correction_requests_time_check CHECK (requested_check_out_time IS NULL OR requested_check_in_time IS NULL OR requested_check_out_time >= requested_check_in_time)'
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_correction_requests');
    }
};
