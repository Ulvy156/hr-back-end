<?php

namespace Database\Seeders;

use App\Models\Attendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeePosition;
use App\Models\LeaveRequest;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DashboardTestingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::transaction(function (): void {
            $this->call(HrmsDemoSeeder::class);

            $employees = Employee::query()
                ->with('user')
                ->whereIn('email', [
                    'alice.ceo@example.com',
                    'helen.hr@example.com',
                    'mark.ops@example.com',
                    'fiona.finance@example.com',
                    'henry.hr@example.com',
                    'emma.employee@example.com',
                    'ethan.staff@example.com',
                ])
                ->get()
                ->keyBy('email');

            $this->seedRecentAttendance(
                $employees['emma.employee@example.com'],
                $employees['emma.employee@example.com']->user,
            );

            $this->seedTodayAttendance($employees);
            $this->seedTodayLeave($employees);
            $this->seedTerminatedEmployee();
        });
    }

    private function seedTodayAttendance(Collection $employees): void
    {
        $today = today();

        $this->upsertAttendance(
            employee: $employees['alice.ceo@example.com'],
            editedBy: $employees['alice.ceo@example.com']->user,
            attendanceDate: $today,
            checkInTime: '07:55:00',
            checkOutTime: '17:10:00',
            status: 'present',
        );

        $this->upsertAttendance(
            employee: $employees['helen.hr@example.com'],
            editedBy: $employees['helen.hr@example.com']->user,
            attendanceDate: $today,
            checkInTime: '09:15:00',
            checkOutTime: null,
            status: 'late',
        );

        $this->upsertAttendance(
            employee: $employees['mark.ops@example.com'],
            editedBy: $employees['mark.ops@example.com']->user,
            attendanceDate: $today,
            checkInTime: '08:00:00',
            checkOutTime: '17:05:00',
            status: 'present',
        );

        $this->upsertAttendance(
            employee: $employees['henry.hr@example.com'],
            editedBy: $employees['henry.hr@example.com']->user,
            attendanceDate: $today,
            checkInTime: '08:10:00',
            checkOutTime: '17:00:00',
            status: 'present',
        );

        $this->upsertAttendance(
            employee: $employees['emma.employee@example.com'],
            editedBy: $employees['emma.employee@example.com']->user,
            attendanceDate: $today,
            checkInTime: '08:05:00',
            checkOutTime: null,
            status: 'present',
        );

        Attendance::query()
            ->whereIn('employee_id', [
                $employees['fiona.finance@example.com']->id,
                $employees['ethan.staff@example.com']->id,
            ])
            ->whereDate('attendance_date', $today->toDateString())
            ->delete();
    }

    private function seedRecentAttendance(Employee $employee, ?User $editedBy): void
    {
        $dates = collect(range(1, 4))
            ->map(fn (int $daysAgo): Carbon => today()->copy()->subDays($daysAgo))
            ->filter(fn (Carbon $date): bool => $date->gte(now()->startOfWeek()));

        foreach ($dates as $index => $date) {
            $this->upsertAttendance(
                employee: $employee,
                editedBy: $editedBy,
                attendanceDate: $date,
                checkInTime: $index === 0 ? '09:05:00' : '08:00:00',
                checkOutTime: '17:00:00',
                status: $index === 0 ? 'late' : 'present',
            );
        }
    }

    private function seedTodayLeave(Collection $employees): void
    {
        LeaveRequest::query()->updateOrCreate(
            [
                'employee_id' => $employees['fiona.finance@example.com']->id,
                'start_date' => today()->toDateString(),
                'end_date' => today()->toDateString(),
            ],
            [
                'type' => 'annual',
                'manager_approved_by' => $employees['alice.ceo@example.com']->id,
                'manager_approved_at' => now()->subDay(),
                'hr_approved_by' => $employees['helen.hr@example.com']->id,
                'hr_approved_at' => now()->subHours(12),
                'status' => 'hr_approved',
            ]
        );
    }

    private function seedTerminatedEmployee(): void
    {
        $department = Department::query()->firstOrCreate(
            ['name' => 'Archive Team'],
            ['parent_id' => null]
        );

        $position = Position::query()->firstOrCreate([
            'title' => 'Former Staff',
        ]);

        $user = User::query()->updateOrCreate(
            ['email' => 'tony.former@example.com'],
            [
                'name' => 'Tony Former',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );

        $employee = Employee::query()->updateOrCreate(
            ['email' => 'tony.former@example.com'],
            [
                'user_id' => $user->id,
                'department_id' => $department->id,
                'current_position_id' => $position->id,
                'manager_id' => null,
                'first_name' => 'Tony',
                'last_name' => 'Former',
                'email' => 'tony.former@example.com',
                'phone' => '+85510000008',
                'hire_date' => '2023-01-01',
                'status' => 'terminated',
            ]
        );

        $role = Role::query()->firstOrCreate(
            ['name' => 'employee'],
            ['description' => 'Regular employee']
        );

        $user->roles()->syncWithoutDetaching([$role->id]);

        EmployeePosition::query()->updateOrCreate(
            [
                'employee_id' => $employee->id,
                'end_date' => null,
            ],
            [
                'position_id' => $position->id,
                'base_salary' => '0.00',
                'start_date' => '2023-01-01',
            ]
        );

        Attendance::query()
            ->where('employee_id', $employee->id)
            ->whereDate('attendance_date', today()->toDateString())
            ->delete();
    }

    private function upsertAttendance(
        Employee $employee,
        ?User $editedBy,
        Carbon $attendanceDate,
        string $checkInTime,
        ?string $checkOutTime,
        string $status,
    ): void {
        Attendance::query()->updateOrCreate(
            [
                'employee_id' => $employee->id,
                'attendance_date' => $attendanceDate->toDateString(),
            ],
            [
                'edited_by' => $editedBy?->id,
                'check_in' => $attendanceDate->copy()->setTimeFromTimeString($checkInTime),
                'check_out' => $checkOutTime !== null
                    ? $attendanceDate->copy()->setTimeFromTimeString($checkOutTime)
                    : null,
                'status' => $status,
            ]
        );
    }
}
