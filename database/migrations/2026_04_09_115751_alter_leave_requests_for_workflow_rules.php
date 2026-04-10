<?php

use App\LeaveTypeCode;
use App\Services\Leave\LeaveRequestStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE leave_requests DROP CONSTRAINT IF EXISTS leave_requests_type_check');
        DB::statement('ALTER TABLE leave_requests DROP CONSTRAINT IF EXISTS leave_requests_status_flow_check');
        DB::statement('ALTER TABLE leave_requests DROP CONSTRAINT IF EXISTS leave_requests_status_check');

        $allowedTypes = implode("', '", [
            LeaveTypeCode::Annual->value,
            LeaveTypeCode::Sick->value,
            LeaveTypeCode::Maternity->value,
            LeaveTypeCode::Special->value,
            LeaveTypeCode::Unpaid->value,
        ]);
        $allowedStatuses = implode("', '", LeaveRequestStatus::all());

        DB::statement(
            "ALTER TABLE leave_requests ADD CONSTRAINT leave_requests_type_check CHECK (type IN ('{$allowedTypes}'))"
        );
        DB::statement(
            "ALTER TABLE leave_requests ADD CONSTRAINT leave_requests_status_check CHECK (status IN ('{$allowedStatuses}'))"
        );
        DB::statement(
            "ALTER TABLE leave_requests ADD CONSTRAINT leave_requests_status_flow_check CHECK ((status = 'pending' AND manager_approved_by IS NULL AND hr_approved_by IS NULL) OR (status = 'manager_approved' AND manager_approved_by IS NOT NULL AND hr_approved_by IS NULL) OR (status = 'hr_approved' AND manager_approved_by IS NOT NULL AND hr_approved_by IS NOT NULL) OR (status = 'rejected') OR (status = 'cancelled'))"
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE leave_requests DROP CONSTRAINT IF EXISTS leave_requests_type_check');
        DB::statement('ALTER TABLE leave_requests DROP CONSTRAINT IF EXISTS leave_requests_status_flow_check');
        DB::statement('ALTER TABLE leave_requests DROP CONSTRAINT IF EXISTS leave_requests_status_check');
        DB::statement(
            "ALTER TABLE leave_requests ADD CONSTRAINT leave_requests_type_check CHECK (type IN ('annual', 'sick', 'unpaid'))"
        );
        DB::statement(
            "ALTER TABLE leave_requests ADD CONSTRAINT leave_requests_status_check CHECK (status IN ('pending', 'manager_approved', 'hr_approved', 'rejected'))"
        );
        DB::statement(
            "ALTER TABLE leave_requests ADD CONSTRAINT leave_requests_status_flow_check CHECK ((status = 'pending' AND manager_approved_by IS NULL AND hr_approved_by IS NULL) OR (status = 'manager_approved' AND manager_approved_by IS NOT NULL AND hr_approved_by IS NULL) OR (status = 'hr_approved' AND manager_approved_by IS NOT NULL AND hr_approved_by IS NOT NULL) OR (status = 'rejected'))"
        );
    }
};
