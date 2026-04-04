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
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->index()->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->date('start_date');
            $table->date('end_date');
            $table->foreignId('manager_approved_by')->nullable()->index()->constrained('employees')->nullOnDelete();
            $table->timestamp('manager_approved_at')->nullable();
            $table->foreignId('hr_approved_by')->nullable()->index()->constrained('employees')->nullOnDelete();
            $table->timestamp('hr_approved_at')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->timestamps();
            $table->index(['employee_id', 'status']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE leave_requests ADD CONSTRAINT leave_requests_type_check CHECK (type IN ('annual', 'sick', 'unpaid'))"
            );
            DB::statement(
                "ALTER TABLE leave_requests ADD CONSTRAINT leave_requests_status_check CHECK (status IN ('pending', 'manager_approved', 'hr_approved', 'rejected'))"
            );
            DB::statement(
                "ALTER TABLE leave_requests ADD CONSTRAINT leave_requests_date_check CHECK (end_date >= start_date)"
            );
            DB::statement(
                'ALTER TABLE leave_requests ADD CONSTRAINT leave_requests_manager_approval_pair_check CHECK ((manager_approved_by IS NULL AND manager_approved_at IS NULL) OR (manager_approved_by IS NOT NULL AND manager_approved_at IS NOT NULL))'
            );
            DB::statement(
                'ALTER TABLE leave_requests ADD CONSTRAINT leave_requests_hr_approval_pair_check CHECK ((hr_approved_by IS NULL AND hr_approved_at IS NULL) OR (hr_approved_by IS NOT NULL AND hr_approved_at IS NOT NULL))'
            );
            DB::statement(
                'ALTER TABLE leave_requests ADD CONSTRAINT leave_requests_hr_after_manager_check CHECK (hr_approved_by IS NULL OR (manager_approved_by IS NOT NULL AND manager_approved_at IS NOT NULL))'
            );
            DB::statement(
                'ALTER TABLE leave_requests ADD CONSTRAINT leave_requests_distinct_approvers_check CHECK (hr_approved_by IS NULL OR manager_approved_by IS NULL OR hr_approved_by <> manager_approved_by)'
            );
            DB::statement(
                "ALTER TABLE leave_requests ADD CONSTRAINT leave_requests_status_flow_check CHECK ((status = 'pending' AND manager_approved_by IS NULL AND hr_approved_by IS NULL) OR (status = 'manager_approved' AND manager_approved_by IS NOT NULL AND hr_approved_by IS NULL) OR (status = 'hr_approved' AND manager_approved_by IS NOT NULL AND hr_approved_by IS NOT NULL) OR (status = 'rejected'))"
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
    }
};
