<?php

namespace Database\Seeders;

use App\EmployeeGender;
use App\EmploymentType;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeePosition;
use App\Models\LeaveRequest;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use App\PermissionName;
use App\Services\Leave\LeaveRequestStatus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;

class HrmsDemoSeeder extends Seeder
{
    private const DEFAULT_PASSWORD = 'password';

    public function run(): void
    {
        DB::transaction(function (): void {
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $this->purgeLegacyDemoEmployees();
            $this->seedPasswordGrantClient();
            $this->call(PayrollTaxRuleSeeder::class);

            $departments = $this->seedDepartments();
            $positions = $this->seedPositions();
            $roles = $this->seedRolesAndPermissions();
            $employees = $this->seedEmployees($departments, $positions, $roles);

            $this->seedLeaveWorkflowScenarios($employees);

            app(PermissionRegistrar::class)->forgetCachedPermissions();
        });
    }

    private function seedPasswordGrantClient(): void
    {
        $clientId = (string) config('services.passport.password_client_id');
        $clientSecret = (string) config('services.passport.password_client_secret');
        $clientName = (string) config('services.passport.password_client_name', 'Seeder Test Password Client');

        if ($clientId === '' || $clientSecret === '') {
            throw new RuntimeException('Passport password client credentials must be configured before seeding.');
        }

        DB::table('oauth_clients')->updateOrInsert(
            ['id' => $clientId],
            [
                'owner_type' => null,
                'owner_id' => null,
                'name' => $clientName,
                'secret' => Hash::make($clientSecret),
                'provider' => 'users',
                'redirect_uris' => '[]',
                'grant_types' => json_encode(['password', 'refresh_token'], JSON_THROW_ON_ERROR),
                'revoked' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    private function purgeLegacyDemoEmployees(): void
    {
        $legacyUsers = User::query()
            ->where('email', 'like', 'demo.employee.%@example.com')
            ->get();

        $legacyUsers->each(function (User $user): void {
            $employee = $user->employee;

            if (! $employee instanceof Employee) {
                $user->delete();

                return;
            }

            LeaveRequest::query()->where('employee_id', $employee->id)->delete();
            EmployeePosition::query()->where('employee_id', $employee->id)->delete();
            $user->syncRoles([]);
            $employee->forceDelete();
            $user->delete();
        });
    }

    /**
     * @return array<string, Department>
     */
    private function seedDepartments(): array
    {
        $executive = Department::query()->updateOrCreate(
            ['name' => 'Executive Office'],
            ['parent_id' => null]
        );

        $humanResources = Department::query()->updateOrCreate(
            ['name' => 'Human Resources'],
            ['parent_id' => $executive->id]
        );

        $operations = Department::query()->updateOrCreate(
            ['name' => 'Operations'],
            ['parent_id' => $executive->id]
        );

        $finance = Department::query()->updateOrCreate(
            ['name' => 'Finance'],
            ['parent_id' => $executive->id]
        );

        return [
            'executive' => $executive,
            'human_resources' => $humanResources,
            'operations' => $operations,
            'finance' => $finance,
        ];
    }

    /**
     * @return array<string, Position>
     */
    private function seedPositions(): array
    {
        return [
            'ceo' => Position::query()->updateOrCreate(['title' => 'Chief Executive Officer']),
            'director' => Position::query()->updateOrCreate(['title' => 'Director']),
            'head_of_hr' => Position::query()->updateOrCreate(['title' => 'Head of HR']),
            'hr_officer' => Position::query()->updateOrCreate(['title' => 'HR Officer']),
            'operations_manager' => Position::query()->updateOrCreate(['title' => 'Operations Manager']),
            'regional_manager' => Position::query()->updateOrCreate(['title' => 'Regional Manager']),
            'operations_staff' => Position::query()->updateOrCreate(['title' => 'Operations Staff']),
            'finance_officer' => Position::query()->updateOrCreate(['title' => 'Finance Officer']),
        ];
    }

    /**
     * @return array<string, Role>
     */
    private function seedRolesAndPermissions(): array
    {
        $this->call(RoleAndPermissionSeeder::class);

        return Role::query()
            ->whereIn('name', Role::managedRoleNames())
            ->where('guard_name', 'api')
            ->get()
            ->keyBy('name')
            ->all();
    }

    /**
     * @param  array<string, Department>  $departments
     * @param  array<string, Position>  $positions
     * @param  array<string, Role>  $roles
     * @return array<string, Employee>
     */
    private function seedEmployees(array $departments, array $positions, array $roles): array
    {
        $alice = $this->upsertEmployeeWithUser(
            user: [
                'name' => 'Alice CEO',
                'email' => 'alice.ceo@example.com',
            ],
            employee: [
                'employee_code' => 'EMP-ADMIN-001',
                'department_id' => $departments['executive']->id,
                'current_position_id' => $positions['ceo']->id,
                'manager_id' => null,
                'first_name' => 'Alice',
                'last_name' => 'CEO',
                'email' => 'alice.ceo@example.com',
                'phone' => '+85510000001',
                'hire_date' => '2022-01-10',
                'employment_type' => EmploymentType::FullTime->value,
                'confirmation_date' => '2022-01-10',
                'gender' => EmployeeGender::Female->value,
                'status' => 'active',
            ],
            roles: [$roles['admin']],
            baseSalary: '4500.00',
        );

        $derek = $this->upsertEmployeeWithUser(
            user: [
                'name' => 'Derek Director',
                'email' => 'derek.director@example.com',
            ],
            employee: [
                'employee_code' => 'EMP-EXE-002',
                'department_id' => $departments['executive']->id,
                'current_position_id' => $positions['director']->id,
                'manager_id' => $alice->id,
                'first_name' => 'Derek',
                'last_name' => 'Director',
                'email' => 'derek.director@example.com',
                'phone' => '+85510000010',
                'hire_date' => '2022-02-01',
                'employment_type' => EmploymentType::FullTime->value,
                'confirmation_date' => '2022-02-01',
                'gender' => EmployeeGender::Male->value,
                'status' => 'active',
            ],
            roles: [$roles['manager']],
            baseSalary: '2800.00',
        );

        $helen = $this->upsertEmployeeWithUser(
            user: [
                'name' => 'Helen HR',
                'email' => 'helen.hr@example.com',
            ],
            employee: [
                'employee_code' => 'EMP-HR-001',
                'department_id' => $departments['human_resources']->id,
                'current_position_id' => $positions['head_of_hr']->id,
                'manager_id' => $alice->id,
                'leave_approver_id' => $derek->id,
                'first_name' => 'Helen',
                'last_name' => 'HR',
                'email' => 'helen.hr@example.com',
                'phone' => '+85510000002',
                'hire_date' => '2022-03-15',
                'employment_type' => EmploymentType::FullTime->value,
                'confirmation_date' => '2022-03-15',
                'gender' => EmployeeGender::Female->value,
                'status' => 'active',
            ],
            roles: [$roles['hr_head']],
            baseSalary: '1900.00',
        );

        $henry = $this->upsertEmployeeWithUser(
            user: [
                'name' => 'Henry Recruiter',
                'email' => 'henry.hr@example.com',
            ],
            employee: [
                'employee_code' => 'EMP-HR-002',
                'department_id' => $departments['human_resources']->id,
                'current_position_id' => $positions['hr_officer']->id,
                'manager_id' => $helen->id,
                'leave_approver_id' => $helen->id,
                'first_name' => 'Henry',
                'last_name' => 'Recruiter',
                'email' => 'henry.hr@example.com',
                'phone' => '+85510000003',
                'hire_date' => '2023-02-20',
                'employment_type' => EmploymentType::FullTime->value,
                'confirmation_date' => '2023-02-20',
                'gender' => EmployeeGender::Male->value,
                'status' => 'active',
            ],
            roles: [$roles['hr']],
            baseSalary: '950.00',
        );

        $mark = $this->upsertEmployeeWithUser(
            user: [
                'name' => 'Mark Ops',
                'email' => 'mark.ops@example.com',
            ],
            employee: [
                'employee_code' => 'EMP-OPS-001',
                'department_id' => $departments['operations']->id,
                'current_position_id' => $positions['operations_manager']->id,
                'manager_id' => $alice->id,
                'leave_approver_id' => $derek->id,
                'first_name' => 'Mark',
                'last_name' => 'Ops',
                'email' => 'mark.ops@example.com',
                'phone' => '+85510000004',
                'hire_date' => '2022-05-01',
                'employment_type' => EmploymentType::FullTime->value,
                'confirmation_date' => '2022-05-01',
                'gender' => EmployeeGender::Male->value,
                'status' => 'active',
            ],
            roles: [$roles['manager']],
            baseSalary: '1650.00',
        );

        $diana = $this->upsertEmployeeWithUser(
            user: [
                'name' => 'Diana Dual',
                'email' => 'diana.dual@example.com',
            ],
            employee: [
                'employee_code' => 'EMP-OPS-002',
                'department_id' => $departments['operations']->id,
                'current_position_id' => $positions['regional_manager']->id,
                'manager_id' => $alice->id,
                'first_name' => 'Diana',
                'last_name' => 'Dual',
                'email' => 'diana.dual@example.com',
                'phone' => '+85510000005',
                'hire_date' => '2022-06-10',
                'employment_type' => EmploymentType::FullTime->value,
                'confirmation_date' => '2022-06-10',
                'gender' => EmployeeGender::Female->value,
                'status' => 'active',
            ],
            roles: [$roles['manager']],
            baseSalary: '1750.00',
            directPermissions: [
                PermissionName::LeaveApproveHr->value,
            ],
        );

        $emma = $this->upsertEmployeeWithUser(
            user: [
                'name' => 'Emma Employee',
                'email' => 'emma.employee@example.com',
            ],
            employee: [
                'employee_code' => 'EMP-OPS-101',
                'department_id' => $departments['operations']->id,
                'current_position_id' => $positions['operations_staff']->id,
                'manager_id' => $mark->id,
                'first_name' => 'Emma',
                'last_name' => 'Employee',
                'email' => 'emma.employee@example.com',
                'phone' => '+85510000006',
                'hire_date' => '2023-04-01',
                'employment_type' => EmploymentType::FullTime->value,
                'confirmation_date' => '2023-04-01',
                'gender' => EmployeeGender::Female->value,
                'status' => 'active',
            ],
            roles: [$roles['employee']],
            baseSalary: '720.00',
        );

        $ethan = $this->upsertEmployeeWithUser(
            user: [
                'name' => 'Ethan Staff',
                'email' => 'ethan.staff@example.com',
            ],
            employee: [
                'employee_code' => 'EMP-OPS-102',
                'department_id' => $departments['operations']->id,
                'current_position_id' => $positions['operations_staff']->id,
                'manager_id' => $mark->id,
                'first_name' => 'Ethan',
                'last_name' => 'Staff',
                'email' => 'ethan.staff@example.com',
                'phone' => '+85510000007',
                'hire_date' => '2023-06-10',
                'employment_type' => EmploymentType::FullTime->value,
                'confirmation_date' => '2023-06-10',
                'gender' => EmployeeGender::Male->value,
                'status' => 'active',
            ],
            roles: [$roles['employee']],
            baseSalary: '700.00',
        );

        $fiona = $this->upsertEmployeeWithUser(
            user: [
                'name' => 'Fiona Finance',
                'email' => 'fiona.finance@example.com',
            ],
            employee: [
                'employee_code' => 'EMP-FIN-001',
                'department_id' => $departments['finance']->id,
                'current_position_id' => $positions['finance_officer']->id,
                'manager_id' => $derek->id,
                'first_name' => 'Fiona',
                'last_name' => 'Finance',
                'email' => 'fiona.finance@example.com',
                'phone' => '+85510000008',
                'hire_date' => '2023-01-12',
                'employment_type' => EmploymentType::FullTime->value,
                'confirmation_date' => '2023-01-12',
                'gender' => EmployeeGender::Female->value,
                'status' => 'active',
            ],
            roles: [$roles['employee']],
            baseSalary: '980.00',
        );

        $nina = $this->upsertEmployeeWithUser(
            user: [
                'name' => 'Nina Dual Staff',
                'email' => 'nina.dual@example.com',
            ],
            employee: [
                'employee_code' => 'EMP-OPS-201',
                'department_id' => $departments['operations']->id,
                'current_position_id' => $positions['operations_staff']->id,
                'manager_id' => $diana->id,
                'first_name' => 'Nina',
                'last_name' => 'DualStaff',
                'email' => 'nina.dual@example.com',
                'phone' => '+85510000009',
                'hire_date' => '2023-08-15',
                'employment_type' => EmploymentType::FullTime->value,
                'confirmation_date' => '2023-08-15',
                'gender' => EmployeeGender::Female->value,
                'status' => 'active',
            ],
            roles: [$roles['employee']],
            baseSalary: '730.00',
        );

        return [
            'alice' => $alice,
            'derek' => $derek,
            'helen' => $helen,
            'henry' => $henry,
            'mark' => $mark,
            'diana' => $diana,
            'emma' => $emma,
            'ethan' => $ethan,
            'fiona' => $fiona,
            'nina' => $nina,
        ];
    }

    /**
     * @param  array<string, Employee>  $employees
     */
    private function seedLeaveWorkflowScenarios(array $employees): void
    {
        $employeeIds = collect($employees)->map->id->all();

        LeaveRequest::query()->whereIn('employee_id', $employeeIds)->delete();

        $this->upsertLeaveRequest(
            employee: $employees['emma'],
            attributes: [
                'type' => 'annual',
                'reason' => 'Pending manager review for normal approval flow.',
                'start_date' => '2026-04-20',
                'end_date' => '2026-04-21',
                'status' => LeaveRequestStatus::Pending,
            ],
        );

        $this->upsertLeaveRequest(
            employee: $employees['ethan'],
            attributes: [
                'type' => 'sick',
                'reason' => 'Waiting for HR approval after manager approval.',
                'start_date' => '2026-04-22',
                'end_date' => '2026-04-22',
                'manager_approved_by' => $employees['mark']->id,
                'manager_approved_at' => now()->subDays(2),
                'status' => LeaveRequestStatus::ManagerApproved,
            ],
        );

        $this->upsertLeaveRequest(
            employee: $employees['fiona'],
            attributes: [
                'type' => 'annual',
                'reason' => 'Already approved through the normal manager then HR flow.',
                'start_date' => '2026-03-20',
                'end_date' => '2026-03-21',
                'manager_approved_by' => $employees['derek']->id,
                'manager_approved_at' => now()->subWeeks(3),
                'hr_approved_by' => $employees['helen']->id,
                'hr_approved_at' => now()->subWeeks(3)->addHour(),
                'status' => LeaveRequestStatus::HrApproved,
            ],
        );

        $this->upsertLeaveRequest(
            employee: $employees['nina'],
            attributes: [
                'type' => 'annual',
                'reason' => 'Pending direct-manager review for the dual-role approval scenario.',
                'start_date' => '2026-04-24',
                'end_date' => '2026-04-25',
                'status' => LeaveRequestStatus::Pending,
            ],
        );

        $this->upsertLeaveRequest(
            employee: $employees['nina'],
            attributes: [
                'type' => 'special',
                'reason' => 'Already approved by the same direct manager with HR authority.',
                'start_date' => '2026-03-12',
                'end_date' => '2026-03-12',
                'manager_approved_by' => $employees['diana']->id,
                'manager_approved_at' => now()->subWeeks(4),
                'hr_approved_by' => $employees['diana']->id,
                'hr_approved_at' => now()->subWeeks(4),
                'status' => LeaveRequestStatus::HrApproved,
            ],
        );

        $this->upsertLeaveRequest(
            employee: $employees['emma'],
            attributes: [
                'type' => 'unpaid',
                'reason' => 'Cancelled by the employee before final approval.',
                'start_date' => '2026-03-05',
                'end_date' => '2026-03-05',
                'status' => LeaveRequestStatus::Cancelled,
            ],
        );

        $this->upsertLeaveRequest(
            employee: $employees['ethan'],
            attributes: [
                'type' => 'annual',
                'reason' => 'Rejected during manager review.',
                'start_date' => '2026-03-10',
                'end_date' => '2026-03-11',
                'status' => LeaveRequestStatus::Rejected,
            ],
        );

        $this->upsertLeaveRequest(
            employee: $employees['mark'],
            attributes: [
                'type' => 'annual',
                'reason' => 'Manager self-approval should be rejected.',
                'start_date' => '2026-04-28',
                'end_date' => '2026-04-28',
                'status' => LeaveRequestStatus::Pending,
            ],
        );

        $this->upsertLeaveRequest(
            employee: $employees['helen'],
            attributes: [
                'type' => 'sick',
                'reason' => 'HR self-approval should be rejected.',
                'start_date' => '2026-04-29',
                'end_date' => '2026-04-29',
                'manager_approved_by' => $employees['derek']->id,
                'manager_approved_at' => now()->subDay(),
                'status' => LeaveRequestStatus::ManagerApproved,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, Role>  $roles
     * @param  array<int, string>  $directPermissions
     */
    private function upsertEmployeeWithUser(
        array $user,
        array $employee,
        array $roles,
        string $baseSalary,
        array $directPermissions = [],
    ): Employee {
        $userModel = User::query()->updateOrCreate(
            ['email' => $user['email']],
            [
                'name' => $user['name'],
                'password' => Hash::make(self::DEFAULT_PASSWORD),
                'email_verified_at' => now(),
            ]
        );

        $employeeModel = Employee::query()->updateOrCreate(
            ['email' => $employee['email']],
            [
                ...$employee,
                'user_id' => $userModel->id,
            ]
        );

        if ($employeeModel->user_id !== $userModel->id) {
            $employeeModel->user()->associate($userModel);
            $employeeModel->save();
        }

        $userModel->syncRoles($roles);
        $userModel->syncPermissions($directPermissions);

        EmployeePosition::query()->updateOrCreate(
            [
                'employee_id' => $employeeModel->id,
                'end_date' => null,
            ],
            [
                'position_id' => $employee['current_position_id'],
                'base_salary' => $baseSalary,
                'start_date' => $employee['hire_date'],
            ]
        );

        return $employeeModel->fresh(['user.roles.permissions']) ?? $employeeModel;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function upsertLeaveRequest(Employee $employee, array $attributes): void
    {
        LeaveRequest::query()->updateOrCreate(
            [
                'employee_id' => $employee->id,
                'start_date' => $attributes['start_date'],
                'end_date' => $attributes['end_date'],
            ],
            [
                'type' => $attributes['type'],
                'reason' => $attributes['reason'],
                'duration_type' => 'full_day',
                'half_day_session' => null,
                'manager_approved_by' => $attributes['manager_approved_by'] ?? null,
                'manager_approved_at' => $attributes['manager_approved_at'] ?? null,
                'hr_approved_by' => $attributes['hr_approved_by'] ?? null,
                'hr_approved_at' => $attributes['hr_approved_at'] ?? null,
                'status' => $attributes['status'],
            ]
        );
    }
}
