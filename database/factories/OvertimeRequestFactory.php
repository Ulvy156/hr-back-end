<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Employee;
use App\Models\OvertimeRequest;
use App\Models\Position;
use App\Models\User;
use App\Services\Overtime\OvertimeApprovalStage;
use App\Services\Overtime\OvertimeRequestStatus;
use App\Services\Overtime\OvertimeType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OvertimeRequest>
 */
class OvertimeRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $employee = Employee::query()->first() ?? $this->createEmployee();
        $overtimeDate = CarbonImmutable::instance(fake()->dateTimeBetween('2026-01-01', '2026-12-31'));

        return [
            'employee_id' => $employee->id,
            'overtime_date' => $overtimeDate->toDateString(),
            'start_time' => '18:00:00',
            'end_time' => '20:00:00',
            'reason' => fake()->sentence(),
            'status' => OvertimeRequestStatus::Pending,
            'approval_stage' => OvertimeApprovalStage::ManagerReview,
            'manager_approved_by' => null,
            'manager_approved_at' => null,
            'rejected_by' => null,
            'rejected_at' => null,
            'rejection_reason' => null,
            'minutes' => 120,
            'overtime_type' => $overtimeDate->isWeekend() ? OvertimeType::Weekend : OvertimeType::Normal,
        ];
    }

    private function createEmployee(): Employee
    {
        $department = Department::query()->create([
            'name' => 'Overtime Factory '.str()->random(6),
        ]);
        $position = Position::query()->create([
            'title' => 'Overtime Factory '.str()->random(6),
        ]);
        $user = User::factory()->create();

        return Employee::query()->create([
            'user_id' => $user->id,
            'department_id' => $department->id,
            'current_position_id' => $position->id,
            'employee_code' => 'OT'.fake()->unique()->numerify('####'),
            'first_name' => 'Overtime',
            'last_name' => 'Employee',
            'email' => fake()->unique()->safeEmail(),
            'phone' => '012345678',
            'hire_date' => '2026-01-01',
            'employment_type' => 'full_time',
            'status' => 'active',
        ]);
    }
}
