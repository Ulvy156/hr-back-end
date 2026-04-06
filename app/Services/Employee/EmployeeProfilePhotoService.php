<?php

namespace App\Services\Employee;

use App\Models\Employee;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Storage\R2StorageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class EmployeeProfilePhotoService
{
    public function __construct(
        private R2StorageService $r2StorageService,
        private AuditLogService $auditLogService,
    ) {}

    public function upload(int $employeeId, UploadedFile $profilePhoto, ?User $actor = null): Employee
    {
        $employee = Employee::query()
            ->with(['user', 'department', 'branch', 'position', 'shift', 'manager'])
            ->findOrFail($employeeId);

        $existingPhotoPath = $employee->profilePhotoStoragePath();
        $uploadedPhotoPath = $this->r2StorageService->upload(
            $profilePhoto,
            sprintf('employees/%d/profile', $employee->id),
        );

        try {
            DB::transaction(function () use ($employee, $uploadedPhotoPath): void {
                $employee->forceFill([
                    'profile_photo' => $uploadedPhotoPath,
                    'profile_photo_path' => $uploadedPhotoPath,
                ])->save();
            });
        } catch (Throwable $throwable) {
            $this->r2StorageService->deleteIfExists($uploadedPhotoPath);

            throw $throwable;
        }

        if ($existingPhotoPath !== null && $existingPhotoPath !== $uploadedPhotoPath) {
            try {
                $this->r2StorageService->deleteIfExists($existingPhotoPath);
            } catch (Throwable $throwable) {
                report($throwable);
            }
        }

        $refreshedEmployee = $employee->fresh(['user', 'department', 'branch', 'position', 'shift', 'manager']);

        if ($refreshedEmployee === null) {
            throw new RuntimeException('Unable to refresh employee after uploading profile photo.');
        }

        $this->auditLogService->log(
            'employee',
            'profile_photo_uploaded',
            'employee.profile_photo_uploaded',
            $actor,
            $refreshedEmployee,
            [
                'employee_id' => $refreshedEmployee->id,
                'old_profile_photo_path' => $existingPhotoPath,
                'new_profile_photo_path' => $uploadedPhotoPath,
            ],
        );

        return $refreshedEmployee;
    }
}
