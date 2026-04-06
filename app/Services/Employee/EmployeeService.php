<?php

namespace App\Services\Employee;

use App\EmployeeStatus;
use App\Models\Employee;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmployeeService
{
    /**
     * @var array<int, string>
     */
    private const ALLOWED_INCLUDES = [
        'user',
        'addresses',
        'educations',
        'emergency_contacts',
        'employee_positions',
    ];

    /**
     * @var array<int, string>
     */
    private const ALLOWED_SORT_COLUMNS = [
        'id',
        'employee_code',
        'first_name',
        'last_name',
        'email',
        'status',
        'hire_date',
        'created_at',
        'updated_at',
    ];

    public function __construct(private AuditLogService $auditLogService) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $perPage = min(max((int) ($filters['per_page'] ?? 15), 1), 100);

        return $this->filteredQuery($filters)
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?User $actor = null): Employee
    {
        return DB::transaction(function () use ($data, $actor): Employee {
            $employee = Employee::query()->create($this->employeePayload($data));

            if ($employee->employee_code === null) {
                $employee->forceFill([
                    'employee_code' => $this->generatedEmployeeCode($employee),
                ])->save();
            }

            $this->syncEmergencyContacts($employee, $data);
            $this->syncAddresses($employee, $data);
            $this->syncEducations($employee, $data);
            $this->syncEmployeePositions($employee, $data);

            $freshEmployee = $employee->fresh();

            if ($freshEmployee === null) {
                abort(500, 'Unable to refresh employee after creation.');
            }

            $refreshedEmployee = $this->loadForShow($freshEmployee, $this->requestedIncludes($data));

            if ($actor !== null) {
                $this->auditLogService->log(
                    'employee',
                    'employee_created',
                    'employee.created',
                    $actor,
                    $refreshedEmployee,
                    [
                        'employee_id' => $refreshedEmployee->id,
                        'employee_code' => $refreshedEmployee->employee_code,
                    ],
                );
            }

            return $refreshedEmployee;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(int $id, array $data, ?User $actor = null): Employee
    {
        return DB::transaction(function () use ($id, $data, $actor): Employee {
            $employee = Employee::query()->findOrFail($id);

            $employee->update($this->employeePayload($data, $employee));
            $this->syncEmergencyContacts($employee, $data);
            $this->syncAddresses($employee, $data);
            $this->syncEducations($employee, $data);
            $this->syncEmployeePositions($employee, $data);

            $freshEmployee = $employee->fresh();

            if ($freshEmployee === null) {
                abort(500, 'Unable to refresh employee after update.');
            }

            $refreshedEmployee = $this->loadForShow($freshEmployee, $this->requestedIncludes($data));

            if ($actor !== null) {
                $this->auditLogService->log(
                    'employee',
                    'employee_updated',
                    'employee.updated',
                    $actor,
                    $refreshedEmployee,
                    [
                        'employee_id' => $refreshedEmployee->id,
                        'employee_code' => $refreshedEmployee->employee_code,
                    ],
                );
            }

            return $refreshedEmployee;
        });
    }

    public function delete(int $id, ?User $actor = null): void
    {
        $employee = Employee::query()->findOrFail($id);
        $employee->delete();

        if ($actor !== null) {
            $this->auditLogService->log(
                'employee',
                'employee_deleted',
                'employee.deleted',
                $actor,
                $employee,
                [
                    'employee_id' => $employee->id,
                    'employee_code' => $employee->employee_code,
                ],
            );
        }
    }

    public function restore(int $id, ?User $actor = null): Employee
    {
        $employee = Employee::query()->withTrashed()->findOrFail($id);
        $employee->restore();

        $freshEmployee = $employee->fresh();

        if ($freshEmployee === null) {
            abort(500, 'Unable to refresh employee after restore.');
        }

        $restoredEmployee = $this->loadForShow($freshEmployee);

        if ($actor !== null) {
            $this->auditLogService->log(
                'employee',
                'employee_restored',
                'employee.restored',
                $actor,
                $restoredEmployee,
                [
                    'employee_id' => $restoredEmployee->id,
                    'employee_code' => $restoredEmployee->employee_code,
                ],
            );
        }

        return $restoredEmployee;
    }

    public function activate(int $id, ?User $actor = null): Employee
    {
        return $this->updateStatus($id, EmployeeStatus::Active, $actor, 'employee_activated', 'employee.activated');
    }

    public function deactivate(int $id, ?User $actor = null): Employee
    {
        return $this->updateStatus($id, EmployeeStatus::Inactive, $actor, 'employee_deactivated', 'employee.deactivated');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function terminate(int $id, array $data = [], ?User $actor = null): Employee
    {
        $employee = Employee::query()->findOrFail($id);
        $previousStatus = $employee->status;

        $employee->forceFill([
            'status' => EmployeeStatus::Terminated,
            'termination_date' => $data['termination_date'] ?? $employee->termination_date ?? now()->toDateString(),
            'last_working_date' => $data['last_working_date'] ?? $employee->last_working_date ?? $data['termination_date'] ?? now()->toDateString(),
        ])->save();

        $freshEmployee = $employee->fresh();

        if ($freshEmployee === null) {
            abort(500, 'Unable to refresh employee after termination.');
        }

        $terminatedEmployee = $this->loadForShow($freshEmployee);

        if ($actor !== null) {
            $this->auditLogService->log(
                'employee',
                'employee_terminated',
                'employee.terminated',
                $actor,
                $terminatedEmployee,
                [
                    'employee_id' => $terminatedEmployee->id,
                    'employee_code' => $terminatedEmployee->employee_code,
                    'previous_status' => $previousStatus?->value,
                    'status' => $terminatedEmployee->status?->value,
                    'termination_date' => $terminatedEmployee->termination_date?->toDateString(),
                    'last_working_date' => $terminatedEmployee->last_working_date?->toDateString(),
                ],
            );
        }

        return $terminatedEmployee;
    }

    public function unterminate(int $id, ?User $actor = null): Employee
    {
        $employee = Employee::query()->findOrFail($id);
        $previousStatus = $employee->status;

        $employee->forceFill([
            'status' => EmployeeStatus::Active,
            'termination_date' => null,
            'last_working_date' => null,
        ])->save();

        $freshEmployee = $employee->fresh();

        if ($freshEmployee === null) {
            abort(500, 'Unable to refresh employee after untermination.');
        }

        $unterminatedEmployee = $this->loadForShow($freshEmployee);

        if ($actor !== null) {
            $this->auditLogService->log(
                'employee',
                'employee_unterminated',
                'employee.unterminated',
                $actor,
                $unterminatedEmployee,
                [
                    'employee_id' => $unterminatedEmployee->id,
                    'employee_code' => $unterminatedEmployee->employee_code,
                    'previous_status' => $previousStatus?->value,
                    'status' => $unterminatedEmployee->status?->value,
                ],
            );
        }

        return $unterminatedEmployee;
    }

    /**
     * @param  array<int, string>  $includes
     */
    public function findAccessible(int $id, User $authenticatedUser, array $includes = []): Employee
    {
        $employee = $this->loadForShow(
            Employee::query()->findOrFail($id),
            $includes,
        );

        $this->ensureCanView($employee, $authenticatedUser);

        return $employee;
    }

    /**
     * @return Collection<int, Employee>
     */
    public function getByManager(int $managerId): Collection
    {
        Employee::query()->findOrFail($managerId);

        return Employee::query()
            ->with($this->baseRelations())
            ->where('manager_id', $managerId)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function filteredQuery(array $filters = []): Builder
    {
        $query = Employee::query()
            ->with($this->relationsForList($this->requestedIncludes($filters)));

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $terms = preg_split('/\s+/', $search) ?: [];

            $query->where(function (Builder $employeeQuery) use ($terms): void {
                foreach ($terms as $term) {
                    $employeeQuery->where(function (Builder $termQuery) use ($term): void {
                        $termQuery
                            ->where('first_name', 'like', '%'.$term.'%')
                            ->orWhere('last_name', 'like', '%'.$term.'%')
                            ->orWhere('email', 'like', '%'.$term.'%')
                            ->orWhere('employee_code', 'like', '%'.$term.'%');
                    });
                }
            });
        }

        $query
            ->when(isset($filters['status']), fn (Builder $employeeQuery): Builder => $employeeQuery->where('status', $filters['status']))
            ->when(isset($filters['department_id']), fn (Builder $employeeQuery): Builder => $employeeQuery->where('department_id', $filters['department_id']))
            ->when(isset($filters['branch_id']), fn (Builder $employeeQuery): Builder => $employeeQuery->where('branch_id', $filters['branch_id']))
            ->when(isset($filters['current_position_id']), fn (Builder $employeeQuery): Builder => $employeeQuery->where('current_position_id', $filters['current_position_id']))
            ->when(isset($filters['manager_id']), fn (Builder $employeeQuery): Builder => $employeeQuery->where('manager_id', $filters['manager_id']))
            ->when(isset($filters['employment_type']), fn (Builder $employeeQuery): Builder => $employeeQuery->where('employment_type', $filters['employment_type']))
            ->when(isset($filters['hire_date_from']), fn (Builder $employeeQuery): Builder => $employeeQuery->whereDate('hire_date', '>=', $filters['hire_date_from']))
            ->when(isset($filters['hire_date_to']), fn (Builder $employeeQuery): Builder => $employeeQuery->whereDate('hire_date', '<=', $filters['hire_date_to']));

        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortDirection = $filters['sort_direction'] ?? 'desc';

        if (! in_array($sortBy, self::ALLOWED_SORT_COLUMNS, true)) {
            $sortBy = 'created_at';
        }

        if (! in_array($sortDirection, ['asc', 'desc'], true)) {
            $sortDirection = 'desc';
        }

        return $query
            ->orderBy($sortBy, $sortDirection)
            ->orderByDesc('id');
    }

    /**
     * @return array<int, string>
     */
    private function baseRelations(): array
    {
        return [
            'department',
            'branch',
            'currentPosition',
            'shift',
            'manager',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function addressRelations(): array
    {
        return [
            'addresses.province',
            'addresses.district',
            'addresses.commune',
            'addresses.village',
        ];
    }

    /**
     * @param  array<int, string>  $includes
     * @return array<int, string>
     */
    private function relationsForList(array $includes = []): array
    {
        return array_values(array_unique([
            ...$this->baseRelations(),
            ...$this->mapIncludesToRelations($includes),
        ]));
    }

    /**
     * @param  array<int, string>  $includes
     */
    private function loadForShow(Employee $employee, array $includes = []): Employee
    {
        return $employee->load(array_values(array_unique([
            ...$this->baseRelations(),
            ...$this->addressRelations(),
            'educations',
            'emergencyContacts',
            'employeePositions.position',
            ...$this->mapIncludesToRelations($includes),
        ])));
    }

    /**
     * @param  array<int, string>  $includes
     * @return array<int, string>
     */
    private function mapIncludesToRelations(array $includes): array
    {
        return collect($includes)
            ->intersect(self::ALLOWED_INCLUDES)
            ->map(fn (string $include): string => match ($include) {
                'addresses' => 'addresses.province',
                'emergency_contacts' => 'emergencyContacts',
                'employee_positions' => 'employeePositions.position',
                default => $include,
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, string>
     */
    private function requestedIncludes(array $payload): array
    {
        $includes = $payload['include'] ?? $payload['includes'] ?? [];

        if (is_string($includes)) {
            $includes = array_filter(array_map('trim', explode(',', $includes)));
        }

        if (! is_array($includes)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $include): string => is_string($include) ? trim($include) : '',
            $includes,
        )));
    }

    private function ensureCanView(Employee $employee, User $authenticatedUser): void
    {
        if ($authenticatedUser->roles()->whereIn('name', ['admin', 'hr'])->exists()) {
            return;
        }

        if (
            $authenticatedUser->roles()->where('name', 'employee')->exists() &&
            $authenticatedUser->employee?->id === $employee->id
        ) {
            return;
        }

        throw new AuthorizationException('Forbidden.');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function employeePayload(array $data, ?Employee $employee = null): array
    {
        $currentPositionId = $this->resolveCurrentPositionId($data, $employee);
        $profilePhotoPath = $this->profilePhotoPathFromPayload($data, $employee);

        return [
            'user_id' => array_key_exists('user_id', $data) ? $data['user_id'] : $employee?->user_id,
            'employee_code' => array_key_exists('employee_code', $data) ? $data['employee_code'] : $employee?->employee_code,
            'department_id' => array_key_exists('department_id', $data) ? $data['department_id'] : $employee?->department_id,
            'branch_id' => array_key_exists('branch_id', $data) ? $data['branch_id'] : $employee?->branch_id,
            'current_position_id' => $currentPositionId,
            'shift_id' => array_key_exists('shift_id', $data) ? $data['shift_id'] : $employee?->shift_id,
            'manager_id' => array_key_exists('manager_id', $data) ? $data['manager_id'] : $employee?->manager_id,
            'first_name' => array_key_exists('first_name', $data) ? $data['first_name'] : $employee?->first_name,
            'last_name' => array_key_exists('last_name', $data) ? $data['last_name'] : $employee?->last_name,
            'email' => array_key_exists('email', $data) ? $data['email'] : $employee?->email,
            'phone' => array_key_exists('phone', $data) ? $data['phone'] : $employee?->phone,
            'date_of_birth' => array_key_exists('date_of_birth', $data) ? $data['date_of_birth'] : $employee?->date_of_birth,
            'gender' => array_key_exists('gender', $data) ? $data['gender'] : $employee?->gender,
            'personal_phone' => array_key_exists('personal_phone', $data) ? $data['personal_phone'] : $employee?->personal_phone,
            'personal_email' => array_key_exists('personal_email', $data) ? $data['personal_email'] : $employee?->personal_email,
            'id_type' => array_key_exists('id_type', $data) ? $data['id_type'] : $employee?->id_type,
            'id_number' => array_key_exists('id_number', $data) ? $data['id_number'] : $employee?->id_number,
            'emergency_contact_name' => array_key_exists('emergency_contact_name', $data) ? $data['emergency_contact_name'] : $employee?->emergency_contact_name,
            'emergency_contact_relationship' => array_key_exists('emergency_contact_relationship', $data) ? $data['emergency_contact_relationship'] : $employee?->emergency_contact_relationship,
            'emergency_contact_phone' => array_key_exists('emergency_contact_phone', $data) ? $data['emergency_contact_phone'] : $employee?->emergency_contact_phone,
            'profile_photo' => $profilePhotoPath,
            'profile_photo_path' => $profilePhotoPath,
            'hire_date' => array_key_exists('hire_date', $data) ? $data['hire_date'] : $employee?->hire_date,
            'employment_type' => array_key_exists('employment_type', $data) ? $data['employment_type'] : $employee?->employment_type,
            'confirmation_date' => array_key_exists('confirmation_date', $data) ? $data['confirmation_date'] : $employee?->confirmation_date,
            'termination_date' => array_key_exists('termination_date', $data) ? $data['termination_date'] : $employee?->termination_date,
            'last_working_date' => array_key_exists('last_working_date', $data) ? $data['last_working_date'] : $employee?->last_working_date,
            'status' => array_key_exists('status', $data) ? $data['status'] : $employee?->status,
        ];
    }

    private function generatedEmployeeCode(Employee $employee): string
    {
        return sprintf('EMP%06d', $employee->id);
    }

    private function updateStatus(int $id, EmployeeStatus $status, ?User $actor, string $event, string $description): Employee
    {
        $employee = Employee::query()->findOrFail($id);
        $previousStatus = $employee->status;

        $employee->forceFill([
            'status' => $status,
        ])->save();

        $freshEmployee = $employee->fresh();

        if ($freshEmployee === null) {
            abort(500, 'Unable to refresh employee after status change.');
        }

        $updatedEmployee = $this->loadForShow($freshEmployee);

        if ($actor !== null) {
            $this->auditLogService->log(
                'employee',
                $event,
                $description,
                $actor,
                $updatedEmployee,
                [
                    'employee_id' => $updatedEmployee->id,
                    'employee_code' => $updatedEmployee->employee_code,
                    'previous_status' => $previousStatus?->value,
                    'status' => $updatedEmployee->status?->value,
                ],
            );
        }

        return $updatedEmployee;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveCurrentPositionId(array $data, ?Employee $employee = null): ?int
    {
        if (array_key_exists('current_position_id', $data) && $data['current_position_id'] !== null) {
            return (int) $data['current_position_id'];
        }

        if (array_key_exists('position_id', $data) && $data['position_id'] !== null) {
            return (int) $data['position_id'];
        }

        if (array_key_exists('employee_positions', $data) && is_array($data['employee_positions'])) {
            $currentPosition = collect($data['employee_positions'])
                ->first(fn (mixed $position): bool => is_array($position) && empty($position['end_date']) && isset($position['position_id']));

            if (is_array($currentPosition)) {
                return (int) $currentPosition['position_id'];
            }
        }

        return $employee?->current_position_id;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function profilePhotoPathFromPayload(array $data, ?Employee $employee = null): ?string
    {
        if (array_key_exists('profile_photo', $data)) {
            return $data['profile_photo'];
        }

        if (array_key_exists('profile_photo_path', $data)) {
            return $data['profile_photo_path'];
        }

        return $employee?->profilePhotoStoragePath();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncEmergencyContacts(Employee $employee, array $data): void
    {
        if (! array_key_exists('emergency_contacts', $data)) {
            return;
        }

        $employee->emergencyContacts()->delete();

        $contacts = collect(Arr::wrap($data['emergency_contacts']))
            ->filter(fn (mixed $contact): bool => is_array($contact))
            ->values();

        if ($contacts->isEmpty()) {
            return;
        }

        $employee->emergencyContacts()->createMany(
            $contacts->map(function (array $contact, int $index): array {
                return [
                    'name' => $contact['name'],
                    'relationship' => $contact['relationship'],
                    'phone' => $contact['phone'],
                    'email' => $contact['email'] ?? null,
                    'is_primary' => (bool) ($contact['is_primary'] ?? $index === 0),
                ];
            })->all()
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncAddresses(Employee $employee, array $data): void
    {
        if (! array_key_exists('addresses', $data)) {
            return;
        }

        $employee->addresses()->delete();

        $addresses = collect(Arr::wrap($data['addresses']))
            ->filter(fn (mixed $address): bool => is_array($address))
            ->values();

        if ($addresses->isEmpty()) {
            return;
        }

        $hasPrimaryAddress = $addresses
            ->contains(fn (mixed $address): bool => is_array($address) && (bool) ($address['is_primary'] ?? false));

        $employee->addresses()->createMany(
            $addresses->map(function (array $address, int $index) use ($hasPrimaryAddress): array {
                return [
                    'address_type' => $address['address_type'],
                    'province_id' => $address['province_id'] ?? null,
                    'district_id' => $address['district_id'] ?? null,
                    'commune_id' => $address['commune_id'] ?? null,
                    'village_id' => $address['village_id'] ?? null,
                    'address_line' => $address['address_line'] ?? null,
                    'street' => $address['street'] ?? null,
                    'house_no' => $address['house_no'] ?? null,
                    'postal_code' => $address['postal_code'] ?? null,
                    'note' => $address['note'] ?? null,
                    'is_primary' => (bool) ($address['is_primary'] ?? (! $hasPrimaryAddress && $index === 0)),
                ];
            })->all()
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncEducations(Employee $employee, array $data): void
    {
        if (! array_key_exists('educations', $data)) {
            return;
        }

        $employee->educations()->delete();

        $educations = collect(Arr::wrap($data['educations']))
            ->filter(fn (mixed $education): bool => is_array($education))
            ->values();

        if ($educations->isEmpty()) {
            return;
        }

        $employee->educations()->createMany(
            $educations->map(fn (array $education): array => [
                'institution_name' => $education['institution_name'],
                'education_level' => $education['education_level'] ?? null,
                'degree' => $education['degree'] ?? null,
                'field_of_study' => $education['field_of_study'] ?? null,
                'start_date' => $education['start_date'] ?? null,
                'end_date' => $education['end_date'] ?? null,
                'graduation_year' => $education['graduation_year'] ?? null,
                'grade' => $education['grade'] ?? null,
                'description' => $education['description'] ?? null,
            ])->all()
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncEmployeePositions(Employee $employee, array $data): void
    {
        if (! array_key_exists('employee_positions', $data)) {
            return;
        }

        $positions = collect(Arr::wrap($data['employee_positions']))
            ->filter(fn (mixed $position): bool => is_array($position))
            ->values();

        if ($positions->isEmpty()) {
            $employee->employeePositions()->delete();

            return;
        }

        $this->ensureAtMostOneCurrentPosition($positions->all());

        $employee->employeePositions()->delete();

        $employee->employeePositions()->createMany(
            $positions->map(fn (array $position): array => [
                'position_id' => $position['position_id'],
                'base_salary' => $position['base_salary'],
                'start_date' => $position['start_date'],
                'end_date' => $position['end_date'] ?? null,
            ])->all()
        );

        $currentPosition = $positions->first(
            fn (array $position): bool => empty($position['end_date'])
        );

        if ($currentPosition !== null && $employee->current_position_id !== $currentPosition['position_id']) {
            $employee->forceFill([
                'current_position_id' => $currentPosition['position_id'],
            ])->save();
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $positions
     */
    private function ensureAtMostOneCurrentPosition(array $positions): void
    {
        $currentPositions = collect($positions)
            ->filter(fn (array $position): bool => empty($position['end_date']))
            ->count();

        if ($currentPositions > 1) {
            throw ValidationException::withMessages([
                'employee_positions' => ['Only one employee position may be current.'],
            ]);
        }
    }
}
