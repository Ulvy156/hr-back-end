<?php

namespace App\Services\Employee;

use App\Models\Employee;
use App\Models\EmployeeAddress;
use Illuminate\Database\Eloquent\Collection;

class EmployeeAddressService
{
    /**
     * @return Collection<int, EmployeeAddress>
     */
    public function index(int $employeeId): Collection
    {
        $employee = $this->findEmployee($employeeId);

        return $employee->addresses()
            ->with(['province', 'district', 'commune', 'village'])
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(int $employeeId, array $data): EmployeeAddress
    {
        $employee = $this->findEmployee($employeeId);
        $isPrimary = $this->resolvePrimaryFlag($employee, (bool) ($data['is_primary'] ?? false));

        if ($isPrimary) {
            $employee->addresses()->update(['is_primary' => false]);
        }

        $address = $employee->addresses()->create([
            ...$data,
            'is_primary' => $isPrimary,
        ]);

        return $address->fresh(['province', 'district', 'commune', 'village']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(int $employeeId, int $addressId, array $data): EmployeeAddress
    {
        $employee = $this->findEmployee($employeeId);
        $address = $this->findAddress($employeeId, $addressId);

        $isPrimary = array_key_exists('is_primary', $data)
            ? (bool) $data['is_primary']
            : (bool) $address->is_primary;

        if ($isPrimary) {
            $employee->addresses()
                ->whereKeyNot($address->id)
                ->update(['is_primary' => false]);
        } elseif (! $employee->addresses()->whereKeyNot($address->id)->where('is_primary', true)->exists()) {
            $isPrimary = true;
        }

        $address->update([
            ...$data,
            'is_primary' => $isPrimary,
        ]);

        return $address->fresh(['province', 'district', 'commune', 'village']);
    }

    public function delete(int $employeeId, int $addressId): void
    {
        $address = $this->findAddress($employeeId, $addressId);
        $employee = $this->findEmployee($employeeId);
        $wasPrimary = (bool) $address->is_primary;

        $address->delete();

        if ($wasPrimary) {
            $employee->addresses()->orderBy('id')->limit(1)->update(['is_primary' => true]);
        }
    }

    private function findEmployee(int $employeeId): Employee
    {
        return Employee::query()->findOrFail($employeeId);
    }

    private function findAddress(int $employeeId, int $addressId): EmployeeAddress
    {
        $this->findEmployee($employeeId);

        return EmployeeAddress::query()
            ->where('employee_id', $employeeId)
            ->findOrFail($addressId);
    }

    private function resolvePrimaryFlag(Employee $employee, bool $isPrimary): bool
    {
        if ($isPrimary) {
            return true;
        }

        return ! $employee->addresses()->where('is_primary', true)->exists();
    }
}
