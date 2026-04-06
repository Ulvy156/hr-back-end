<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeePosition;
use App\Models\LeaveRequest;
use App\Models\Permission;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class HrmsDemoSeeder extends Seeder
{
    private const DEFAULT_PASSWORD = 'password';

    private const EXTRA_EMPLOYEE_COUNT = 50;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        DB::transaction(function (): void {
            $this->syncPostgresSequences();

            $departments = $this->seedDepartments();
            $positions = $this->seedPositions();
            $roles = $this->seedRolesAndPermissions();

            $ceo = $this->upsertEmployeeWithUser(
                user: [
                    'name' => 'Alice CEO',
                    'email' => 'alice.ceo@example.com',
                ],
                employee: [
                    'department_id' => $departments['executive']->id,
                    'current_position_id' => $positions['ceo']->id,
                    'manager_id' => null,
                    'first_name' => 'Alice',
                    'last_name' => 'CEO',
                    'email' => 'alice.ceo@example.com',
                    'phone' => '+85510000001',
                    'hire_date' => '2022-01-10',
                    'status' => 'active',
                ],
                role: $roles['admin'],
                baseSalary: '4500.00',
            );

            $hrManager = $this->upsertEmployeeWithUser(
                user: [
                    'name' => 'Helen HR',
                    'email' => 'helen.hr@example.com',
                ],
                employee: [
                    'department_id' => $departments['human_resources']->id,
                    'current_position_id' => $positions['hr_manager']->id,
                    'manager_id' => $ceo->id,
                    'first_name' => 'Helen',
                    'last_name' => 'HR',
                    'email' => 'helen.hr@example.com',
                    'phone' => '+85510000002',
                    'hire_date' => '2022-03-15',
                    'status' => 'active',
                ],
                role: $roles['hr'],
                baseSalary: '1800.00',
            );

            $operationsManager = $this->upsertEmployeeWithUser(
                user: [
                    'name' => 'Mark Ops',
                    'email' => 'mark.ops@example.com',
                ],
                employee: [
                    'department_id' => $departments['operations']->id,
                    'current_position_id' => $positions['operations_manager']->id,
                    'manager_id' => $ceo->id,
                    'first_name' => 'Mark',
                    'last_name' => 'Ops',
                    'email' => 'mark.ops@example.com',
                    'phone' => '+85510000003',
                    'hire_date' => '2022-05-01',
                    'status' => 'active',
                ],
                role: $roles['manager'],
                baseSalary: '1600.00',
            );

            $financeOfficer = $this->upsertEmployeeWithUser(
                user: [
                    'name' => 'Fiona Finance',
                    'email' => 'fiona.finance@example.com',
                ],
                employee: [
                    'department_id' => $departments['finance']->id,
                    'current_position_id' => $positions['accountant']->id,
                    'manager_id' => $ceo->id,
                    'first_name' => 'Fiona',
                    'last_name' => 'Finance',
                    'email' => 'fiona.finance@example.com',
                    'phone' => '+85510000004',
                    'hire_date' => '2023-01-12',
                    'status' => 'active',
                ],
                role: $roles['employee'],
                baseSalary: '950.00',
            );

            $hrOfficer = $this->upsertEmployeeWithUser(
                user: [
                    'name' => 'Henry Recruiter',
                    'email' => 'henry.hr@example.com',
                ],
                employee: [
                    'department_id' => $departments['human_resources']->id,
                    'current_position_id' => $positions['hr_officer']->id,
                    'manager_id' => $hrManager->id,
                    'first_name' => 'Henry',
                    'last_name' => 'Recruiter',
                    'email' => 'henry.hr@example.com',
                    'phone' => '+85510000005',
                    'hire_date' => '2023-02-20',
                    'status' => 'active',
                ],
                role: $roles['hr'],
                baseSalary: '900.00',
            );

            $employeeOne = $this->upsertEmployeeWithUser(
                user: [
                    'name' => 'Emma Employee',
                    'email' => 'emma.employee@example.com',
                ],
                employee: [
                    'department_id' => $departments['operations']->id,
                    'current_position_id' => $positions['operations_staff']->id,
                    'manager_id' => $operationsManager->id,
                    'first_name' => 'Emma',
                    'last_name' => 'Employee',
                    'email' => 'emma.employee@example.com',
                    'phone' => '+85510000006',
                    'hire_date' => '2023-04-01',
                    'status' => 'active',
                ],
                role: $roles['employee'],
                baseSalary: '700.00',
            );

            $employeeTwo = $this->upsertEmployeeWithUser(
                user: [
                    'name' => 'Ethan Staff',
                    'email' => 'ethan.staff@example.com',
                ],
                employee: [
                    'department_id' => $departments['operations']->id,
                    'current_position_id' => $positions['operations_staff']->id,
                    'manager_id' => $operationsManager->id,
                    'first_name' => 'Ethan',
                    'last_name' => 'Staff',
                    'email' => 'ethan.staff@example.com',
                    'phone' => '+85510000007',
                    'hire_date' => '2023-06-10',
                    'status' => 'active',
                ],
                role: $roles['employee'],
                baseSalary: '680.00',
            );

            $this->seedLeaveRequests(
                employeeOne: $employeeOne,
                employeeTwo: $employeeTwo,
                operationsManager: $operationsManager,
                hrManager: $hrManager,
            );

            $this->seedAdditionalEmployees(
                departments: $departments,
                positions: $positions,
                role: $roles['employee'],
                hrManager: $hrManager,
                operationsManager: $operationsManager,
                financeOfficer: $financeOfficer,
            );
        });
    }

    private function syncPostgresSequences(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ([
            'users',
            'employees',
            'employee_positions',
            'departments',
            'positions',
            'roles',
            'permissions',
            'user_roles',
            'role_permissions',
            'leave_requests',
        ] as $table) {
            $maxId = (int) DB::table($table)->max('id');

            DB::statement(
                "SELECT setval(pg_get_serial_sequence('{$table}', 'id'), ".max($maxId, 1).', '.($maxId > 0 ? 'true' : 'false').')'
            );
        }
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
            'hr_manager' => Position::query()->updateOrCreate(['title' => 'HR Manager']),
            'hr_officer' => Position::query()->updateOrCreate(['title' => 'HR Officer']),
            'operations_manager' => Position::query()->updateOrCreate(['title' => 'Operations Manager']),
            'operations_staff' => Position::query()->updateOrCreate(['title' => 'Operations Staff']),
            'accountant' => Position::query()->updateOrCreate(['title' => 'Accountant']),
        ];
    }

    /**
     * @return array<string, Role>
     */
    private function seedRolesAndPermissions(): array
    {
        $permissions = [
            'manage_users' => Permission::query()->updateOrCreate(
                ['name' => 'manage_users'],
                ['description' => 'Manage system users']
            ),
            'manage_employees' => Permission::query()->updateOrCreate(
                ['name' => 'manage_employees'],
                ['description' => 'Manage employee records']
            ),
            'manage_departments' => Permission::query()->updateOrCreate(
                ['name' => 'manage_departments'],
                ['description' => 'Manage departments']
            ),
            'manage_leave' => Permission::query()->updateOrCreate(
                ['name' => 'manage_leave'],
                ['description' => 'Manage leave requests']
            ),
            'approve_leave_as_manager' => Permission::query()->updateOrCreate(
                ['name' => 'approve_leave_as_manager'],
                ['description' => 'Approve leave as line manager']
            ),
            'approve_leave_as_hr' => Permission::query()->updateOrCreate(
                ['name' => 'approve_leave_as_hr'],
                ['description' => 'Approve leave as HR']
            ),
            'view_payroll' => Permission::query()->updateOrCreate(
                ['name' => 'view_payroll'],
                ['description' => 'View payroll records']
            ),
            'manage_payroll' => Permission::query()->updateOrCreate(
                ['name' => 'manage_payroll'],
                ['description' => 'Manage payroll records']
            ),
        ];

        $roles = [
            'admin' => Role::query()->updateOrCreate(
                ['name' => 'admin'],
                ['description' => 'System administrator']
            ),
            'hr' => Role::query()->updateOrCreate(
                ['name' => 'hr'],
                ['description' => 'Human resources staff']
            ),
            'manager' => Role::query()->updateOrCreate(
                ['name' => 'manager'],
                ['description' => 'Department manager']
            ),
            'employee' => Role::query()->updateOrCreate(
                ['name' => 'employee'],
                ['description' => 'Regular employee']
            ),
        ];

        $roles['admin']->permissions()->sync(collect($permissions)->pluck('id')->all());
        $roles['hr']->permissions()->sync([
            $permissions['manage_employees']->id,
            $permissions['manage_leave']->id,
            $permissions['approve_leave_as_hr']->id,
            $permissions['view_payroll']->id,
            $permissions['manage_payroll']->id,
        ]);
        $roles['manager']->permissions()->sync([
            $permissions['approve_leave_as_manager']->id,
            $permissions['manage_leave']->id,
        ]);
        $roles['employee']->permissions()->sync([
            $permissions['view_payroll']->id,
        ]);

        return $roles;
    }

    private function upsertEmployeeWithUser(
        array $user,
        array $employee,
        Role $role,
        string $baseSalary,
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

        $userModel->roles()->sync([$role->id]);

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

        return $employeeModel;
    }

    private function seedLeaveRequests(
        Employee $employeeOne,
        Employee $employeeTwo,
        Employee $operationsManager,
        Employee $hrManager,
    ): void {
        LeaveRequest::query()->updateOrCreate(
            [
                'employee_id' => $employeeOne->id,
                'start_date' => '2026-04-10',
                'end_date' => '2026-04-12',
            ],
            [
                'type' => 'annual',
                'manager_approved_by' => null,
                'manager_approved_at' => null,
                'hr_approved_by' => null,
                'hr_approved_at' => null,
                'status' => 'pending',
            ]
        );

        LeaveRequest::query()->updateOrCreate(
            [
                'employee_id' => $employeeTwo->id,
                'start_date' => '2026-04-15',
                'end_date' => '2026-04-16',
            ],
            [
                'type' => 'sick',
                'manager_approved_by' => $operationsManager->id,
                'manager_approved_at' => now()->subDay(),
                'hr_approved_by' => null,
                'hr_approved_at' => null,
                'status' => 'manager_approved',
            ]
        );

        LeaveRequest::query()->updateOrCreate(
            [
                'employee_id' => $employeeOne->id,
                'start_date' => '2026-03-20',
                'end_date' => '2026-03-21',
            ],
            [
                'type' => 'annual',
                'manager_approved_by' => $operationsManager->id,
                'manager_approved_at' => now()->subWeeks(2),
                'hr_approved_by' => $hrManager->id,
                'hr_approved_at' => now()->subWeeks(2)->addHour(),
                'status' => 'hr_approved',
            ]
        );
    }

    /**
     * @param  array<string, Department>  $departments
     * @param  array<string, Position>  $positions
     */
    private function seedAdditionalEmployees(
        array $departments,
        array $positions,
        Role $role,
        Employee $hrManager,
        Employee $operationsManager,
        Employee $financeOfficer,
    ): void {
        $departmentAssignments = [
            [
                'department' => $departments['operations'],
                'position' => $positions['operations_staff'],
                'manager' => $operationsManager,
                'salary' => '720.00',
            ],
            [
                'department' => $departments['human_resources'],
                'position' => $positions['hr_officer'],
                'manager' => $hrManager,
                'salary' => '880.00',
            ],
            [
                'department' => $departments['finance'],
                'position' => $positions['accountant'],
                'manager' => $financeOfficer,
                'salary' => '940.00',
            ],
        ];

        for ($index = 1; $index <= self::EXTRA_EMPLOYEE_COUNT; $index++) {
            $assignment = $departmentAssignments[($index - 1) % count($departmentAssignments)];
            $employeeNumber = str_pad((string) $index, 2, '0', STR_PAD_LEFT);

            $this->upsertEmployeeWithUser(
                user: [
                    'name' => 'Demo Employee '.$employeeNumber,
                    'email' => 'demo.employee.'.$employeeNumber.'@example.com',
                ],
                employee: [
                    'department_id' => $assignment['department']->id,
                    'current_position_id' => $assignment['position']->id,
                    'manager_id' => $assignment['manager']->id,
                    'employee_code' => 'EMPDEMO'.$employeeNumber,
                    'first_name' => 'Demo',
                    'last_name' => 'Employee '.$employeeNumber,
                    'email' => 'demo.employee.'.$employeeNumber.'@example.com',
                    'phone' => '0'.str_pad((string) (12000000 + $index), 9, '0', STR_PAD_LEFT),
                    'hire_date' => now()->subDays($index)->toDateString(),
                    'status' => 'active',
                ],
                role: $role,
                baseSalary: $assignment['salary'],
            );
        }
    }
}
