<?php

namespace App\Services\Employee;

use App\Models\Employee;
use App\Models\EmployeeEmergencyContact;
use Illuminate\Database\Eloquent\Collection;

class EmployeeEmergencyContactService
{
    /**
     * @return Collection<int, EmployeeEmergencyContact>
     */
    public function index(int $employeeId): Collection
    {
        $employee = $this->findEmployee($employeeId);

        return $employee->emergencyContacts()
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(int $employeeId, array $data): EmployeeEmergencyContact
    {
        $employee = $this->findEmployee($employeeId);
        $isPrimary = $this->resolvePrimaryFlag($employee, (bool) ($data['is_primary'] ?? false));

        if ($isPrimary) {
            $employee->emergencyContacts()->update(['is_primary' => false]);
        }

        return $employee->emergencyContacts()->create([
            ...$data,
            'is_primary' => $isPrimary,
        ])->fresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(int $employeeId, int $contactId, array $data): EmployeeEmergencyContact
    {
        $employee = $this->findEmployee($employeeId);
        $contact = $this->findContact($employeeId, $contactId);

        $isPrimary = array_key_exists('is_primary', $data)
            ? (bool) $data['is_primary']
            : (bool) $contact->is_primary;

        if ($isPrimary) {
            $employee->emergencyContacts()
                ->whereKeyNot($contact->id)
                ->update(['is_primary' => false]);
        } elseif (! $employee->emergencyContacts()->whereKeyNot($contact->id)->where('is_primary', true)->exists()) {
            $isPrimary = true;
        }

        $contact->update([
            ...$data,
            'is_primary' => $isPrimary,
        ]);

        return $contact->fresh();
    }

    public function delete(int $employeeId, int $contactId): void
    {
        $contact = $this->findContact($employeeId, $contactId);
        $employee = $this->findEmployee($employeeId);
        $wasPrimary = (bool) $contact->is_primary;

        $contact->delete();

        if ($wasPrimary) {
            $employee->emergencyContacts()->orderBy('id')->limit(1)->update(['is_primary' => true]);
        }
    }

    private function findEmployee(int $employeeId): Employee
    {
        return Employee::query()->findOrFail($employeeId);
    }

    private function findContact(int $employeeId, int $contactId): EmployeeEmergencyContact
    {
        $this->findEmployee($employeeId);

        return EmployeeEmergencyContact::query()
            ->where('employee_id', $employeeId)
            ->findOrFail($contactId);
    }

    private function resolvePrimaryFlag(Employee $employee, bool $isPrimary): bool
    {
        if ($isPrimary) {
            return true;
        }

        return ! $employee->emergencyContacts()->where('is_primary', true)->exists();
    }
}
