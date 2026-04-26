<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('backfills linked user name shadows and canonical profile photo paths', function () {
    config()->set('filesystems.disks.r2.url', 'https://cdn.example.com');
    config()->set('filesystems.disks.r2.key', 'test-key');
    config()->set('filesystems.disks.r2.secret', 'test-secret');
    config()->set('filesystems.disks.r2.bucket', 'test-bucket');
    config()->set('filesystems.disks.r2.endpoint', 'https://example-account-id.r2.cloudflarestorage.com');

    $user = User::factory()->create([
        'name' => 'Legacy Account Name',
        'email' => 'linked.employee@example.com',
    ]);

    $department = Department::query()->create([
        'name' => 'Operations',
    ]);
    $position = Position::query()->create([
        'title' => 'Analyst',
    ]);

    $employee = Employee::query()->create([
        'user_id' => $user->id,
        'department_id' => $department->id,
        'current_position_id' => $position->id,
        'first_name' => 'Dara',
        'last_name' => 'Lim',
        'email' => 'dara.work@example.com',
        'phone' => '012345678',
        'profile_photo' => 'employees/1/profile/avatar.jpg',
        'profile_photo_path' => null,
        'hire_date' => '2026-04-01',
        'status' => 'active',
    ]);

    $migration = require database_path('migrations/2026_04_22_210012_sync_linked_user_name_shadows_and_profile_photo_paths.php');
    $migration->up();

    $user->refresh();
    $employee->refresh();

    expect($user->getRawOriginal('name'))->toBe('Dara Lim')
        ->and($user->displayName())->toBe('Dara Lim')
        ->and($employee->getRawOriginal('profile_photo_path'))->toBe('employees/1/profile/avatar.jpg')
        ->and($employee->profilePhotoStoragePath())->toBe('employees/1/profile/avatar.jpg');
});
