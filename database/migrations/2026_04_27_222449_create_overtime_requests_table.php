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
        Schema::create('overtime_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->index()->constrained()->restrictOnDelete();
            $table->date('overtime_date')->index();
            $table->time('start_time');
            $table->time('end_time');
            $table->text('reason');
            $table->string('status', 20)->default('pending')->index();
            $table->string('approval_stage', 20)->default('manager_review')->index();
            $table->foreignId('manager_approved_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('manager_approved_at')->nullable();
            $table->foreignId('hr_approved_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('hr_approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->unsignedInteger('minutes');
            $table->string('overtime_type', 20);
            $table->timestamps();

            $table->index(['employee_id', 'overtime_date']);
            $table->index(['employee_id', 'status']);
            $table->index(['employee_id', 'overtime_date', 'status']);
        });

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

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('overtime_requests');
    }
};
