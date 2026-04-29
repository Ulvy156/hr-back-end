<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\OvertimeRequest;
use App\Services\Overtime\OvertimeApprovalStage;
use App\Services\Overtime\OvertimeRequestStatus;
use App\Services\Overtime\OvertimeType;
use Illuminate\Database\Seeder;

class OvertimeRequestSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $employee = Employee::query()->orderBy('id')->first();

        if (! $employee instanceof Employee) {
            return;
        }

        OvertimeRequest::query()->updateOrCreate(
            [
                'employee_id' => $employee->id,
                'overtime_date' => '2026-04-18',
                'start_time' => '18:00:00',
                'end_time' => '20:00:00',
            ],
            [
                'reason' => 'Demo seeded overtime request.',
                'status' => OvertimeRequestStatus::Pending,
                'approval_stage' => OvertimeApprovalStage::ManagerReview,
                'manager_approved_by' => null,
                'manager_approved_at' => null,
                'rejected_by' => null,
                'rejected_at' => null,
                'rejection_reason' => null,
                'minutes' => 120,
                'overtime_type' => OvertimeType::Weekend,
            ],
        );
    }
}
