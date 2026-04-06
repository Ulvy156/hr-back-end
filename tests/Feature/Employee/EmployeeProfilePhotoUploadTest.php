<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('filesystems.disks.r2.key', 'test-key');
    config()->set('filesystems.disks.r2.secret', 'test-secret');
    config()->set('filesystems.disks.r2.bucket', 'test-bucket');
    config()->set('filesystems.disks.r2.endpoint', 'https://example-account-id.r2.cloudflarestorage.com');
    config()->set('filesystems.disks.r2.url', 'https://cdn.example.com');
});

it('uploads an employee profile photo to the r2 disk and returns a usable url', function () {
    Storage::fake('r2');

    $admin = createEmployeePhotoActor('admin');
    $employee = createEmployeeForPhotoUpload();

    Passport::actingAs($admin);

    $response = $this->post(
        "/api/employees/{$employee->id}/profile-photo",
        [
            'profile_photo' => UploadedFile::fake()->image('avatar.jpg', 300, 300),
        ],
        [
            'Accept' => 'application/json',
        ],
    );

    $response
        ->assertOk()
        ->assertJsonPath('message', 'Profile photo uploaded successfully.')
        ->assertJsonPath('employee.id', $employee->id)
        ->assertJsonPath(
            'employee.profile_photo_path',
            fn (string $path): bool => str_starts_with($path, 'employees/'.$employee->id.'/profile/')
        )
        ->assertJsonPath(
            'employee.profile_photo',
            fn (string $url): bool => str_starts_with($url, 'https://cdn.example.com/employees/'.$employee->id.'/profile/')
        );

    $storedPath = Employee::query()->findOrFail($employee->id)->profilePhotoStoragePath();

    expect($storedPath)->not->toBeNull();

    Storage::disk('r2')->assertExists($storedPath);
});

it('replaces an existing employee profile photo and deletes the old file from r2', function () {
    Storage::fake('r2');

    $hrUser = createEmployeePhotoActor('hr');
    $employee = createEmployeeForPhotoUpload([
        'profile_photo' => 'employees/1/profile/old-photo.jpg',
        'profile_photo_path' => 'employees/1/profile/old-photo.jpg',
    ]);

    Storage::disk('r2')->put('employees/1/profile/old-photo.jpg', 'old-file');

    Passport::actingAs($hrUser);

    $this->post(
        "/api/employees/{$employee->id}/profile-photo",
        [
            'profile_photo' => UploadedFile::fake()->image('replacement.png', 320, 320),
        ],
        [
            'Accept' => 'application/json',
        ],
    )->assertOk();

    $freshEmployee = Employee::query()->findOrFail($employee->id);
    $newStoredPath = $freshEmployee->profilePhotoStoragePath();

    expect($newStoredPath)->not->toBe('employees/1/profile/old-photo.jpg')
        ->and($newStoredPath)->not->toBeNull();

    Storage::disk('r2')->assertMissing('employees/1/profile/old-photo.jpg');
    Storage::disk('r2')->assertExists($newStoredPath);
});

it('fails clearly when the public r2 url is missing for an employee with a stored profile photo', function () {
    Storage::fake('r2');

    $admin = createEmployeePhotoActor('admin');
    $employee = createEmployeeForPhotoUpload([
        'profile_photo' => 'employees/99/profile/avatar.jpg',
        'profile_photo_path' => 'employees/99/profile/avatar.jpg',
    ]);

    Passport::actingAs($admin);
    config()->set('filesystems.disks.r2.url', null);

    try {
        $this->withoutExceptionHandling()->getJson("/api/employees/{$employee->id}");

        $this->fail('Expected a LogicException to be thrown when R2_PUBLIC_URL is missing.');
    } catch (LogicException $exception) {
        expect($exception->getMessage())->toBe('R2_PUBLIC_URL is not configured for the r2 filesystem disk.');
    }
});

it('forbids normal employees from uploading employee profile photos', function () {
    Storage::fake('r2');

    $employeeUser = createEmployeePhotoActor('employee');
    $employee = createEmployeeForPhotoUpload();

    Passport::actingAs($employeeUser);

    $this->post(
        "/api/employees/{$employee->id}/profile-photo",
        [
            'profile_photo' => UploadedFile::fake()->image('avatar.webp', 200, 200),
        ],
        [
            'Accept' => 'application/json',
        ],
    )->assertForbidden();
});

function createEmployeePhotoActor(string $roleName): User
{
    $user = User::factory()->create();
    $role = Role::query()->firstOrCreate(
        ['name' => $roleName],
        ['description' => ucfirst($roleName)],
    );

    $department = Department::query()->create([
        'name' => fake()->unique()->company(),
    ]);

    $position = Position::query()->create([
        'title' => fake()->unique()->jobTitle(),
    ]);

    Employee::query()->create([
        'user_id' => $user->id,
        'department_id' => $department->id,
        'current_position_id' => $position->id,
        'first_name' => fake()->firstName(),
        'last_name' => fake()->lastName(),
        'email' => fake()->unique()->safeEmail(),
        'phone' => '0'.fake()->numerify('#########'),
        'hire_date' => now()->subYear()->toDateString(),
        'status' => 'active',
    ]);

    $user->roles()->syncWithoutDetaching([$role->id]);

    return $user->fresh('employee', 'roles');
}

/**
 * @param  array<string, mixed>  $overrides
 */
function createEmployeeForPhotoUpload(array $overrides = []): Employee
{
    $department = Department::query()->create([
        'name' => fake()->unique()->company(),
    ]);

    $position = Position::query()->create([
        'title' => fake()->unique()->jobTitle(),
    ]);

    $user = User::factory()->create();

    return Employee::query()->create(array_merge([
        'user_id' => $user->id,
        'department_id' => $department->id,
        'current_position_id' => $position->id,
        'first_name' => fake()->firstName(),
        'last_name' => fake()->lastName(),
        'email' => fake()->unique()->safeEmail(),
        'phone' => '0'.fake()->numerify('#########'),
        'hire_date' => now()->subYear()->toDateString(),
        'status' => 'active',
    ], $overrides));
}
