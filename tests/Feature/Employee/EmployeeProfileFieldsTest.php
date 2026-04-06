<?php

use App\Models\Branch;
use App\Models\Commune;
use App\Models\Department;
use App\Models\District;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Province;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use App\Models\Village;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('filesystems.disks.r2.url', 'https://cdn.example.com');
    config()->set('filesystems.disks.r2.key', 'test-key');
    config()->set('filesystems.disks.r2.secret', 'test-secret');
    config()->set('filesystems.disks.r2.bucket', 'test-bucket');
    config()->set('filesystems.disks.r2.endpoint', 'https://example-account-id.r2.cloudflarestorage.com');
});

it('stores the new employee personal information fields without breaking existing fields', function () {
    $admin = createEmployeeAdmin();
    $department = Department::query()->create(['name' => 'People']);
    $branch = Branch::factory()->create(['name' => 'Phnom Penh HQ']);
    $shift = Shift::factory()->create(['name' => 'Morning Shift']);
    $position = Position::query()->create(['title' => 'HR Officer']);
    $employeeUser = User::factory()->create();
    $location = createLocationHierarchy();

    Passport::actingAs($admin);

    $response = $this->postJson('/api/employees', [
        'user_id' => $employeeUser->id,
        'employee_code' => 'EMP-PEOPLE-001',
        'department_id' => $department->id,
        'branch_id' => $branch->id,
        'position_id' => $position->id,
        'shift_id' => $shift->id,
        'first_name' => 'Nita',
        'last_name' => 'Sok',
        'email' => 'nita.work@example.com',
        'phone' => '012111222',
        'date_of_birth' => '1998-05-10',
        'gender' => 'female',
        'personal_phone' => '098111222',
        'personal_email' => 'nita.personal@example.com',
        'addresses' => [
            [
                'address_type' => 'current',
                'province_id' => $location['province']->id,
                'district_id' => $location['district']->id,
                'commune_id' => $location['commune']->id,
                'village_id' => $location['village']->id,
                'address_line' => 'Apartment 10B',
                'street' => 'Street 271',
                'house_no' => '10B',
                'postal_code' => '12000',
                'note' => 'Near the market',
                'is_primary' => true,
            ],
            [
                'address_type' => 'permanent',
                'address_line' => 'Family home',
                'street' => 'National Road 6',
                'house_no' => '22A',
                'postal_code' => '13000',
            ],
        ],
        'id_type' => 'national_id',
        'id_number' => 'ID-2026-001',
        'emergency_contact_name' => 'Sok Dara',
        'emergency_contact_relationship' => 'sibling',
        'emergency_contact_phone' => '011333444',
        'profile_photo_path' => 'employees/photos/nita-sok.jpg',
        'hire_date' => '2026-04-01',
        'employment_type' => 'full_time',
        'confirmation_date' => '2026-07-01',
        'status' => 'active',
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('employee_code', 'EMP-PEOPLE-001')
        ->assertJsonPath('email', 'nita.work@example.com')
        ->assertJsonPath('personal_email', 'nita.personal@example.com')
        ->assertJsonPath('gender', 'female')
        ->assertJsonPath('employment_type', 'full_time')
        ->assertJsonPath('branch.name', 'Phnom Penh HQ')
        ->assertJsonPath('shift.name', 'Morning Shift')
        ->assertJsonPath('addresses.0.address_type', 'current')
        ->assertJsonPath('addresses.0.province.name_en', $location['province']->name_en)
        ->assertJsonPath('addresses.1.address_type', 'permanent')
        ->assertJsonPath('full_name', 'Nita Sok');

    $employee = Employee::query()->with('addresses')->first();

    expect($employee?->personal_email)->toBe('nita.personal@example.com')
        ->and($employee?->id_number)->toBe('ID-2026-001')
        ->and($employee?->branch_id)->toBe($branch->id)
        ->and($employee?->shift_id)->toBe($shift->id)
        ->and($employee?->addresses)->toHaveCount(2)
        ->and($employee?->addresses->first()?->address_type?->value)->toBe('current')
        ->and($employee?->addresses->first()?->province_id)->toBe($location['province']->id);
});

it('updates the new employee personal information fields and keeps nullable fields backward compatible', function () {
    $admin = createEmployeeAdmin();
    $department = Department::query()->create(['name' => 'Operations']);
    $branch = Branch::factory()->create(['name' => 'Siem Reap Branch']);
    $shift = Shift::factory()->create(['name' => 'Support Shift']);
    $position = Position::query()->create(['title' => 'Coordinator']);
    $employeeUser = User::factory()->create();
    $location = createLocationHierarchy();

    $employee = Employee::query()->create([
        'user_id' => $employeeUser->id,
        'department_id' => $department->id,
        'current_position_id' => $position->id,
        'employee_code' => 'EMP000123',
        'first_name' => 'Chan',
        'last_name' => 'Vanna',
        'email' => 'chan.work@example.com',
        'phone' => '012123123',
        'hire_date' => '2025-01-10',
        'status' => 'active',
    ]);

    Passport::actingAs($admin);

    $response = $this->putJson("/api/employees/{$employee->id}", [
        'user_id' => $employeeUser->id,
        'employee_code' => 'EMP000123',
        'department_id' => $department->id,
        'branch_id' => $branch->id,
        'position_id' => $position->id,
        'shift_id' => $shift->id,
        'manager_id' => null,
        'first_name' => 'Chan',
        'last_name' => 'Vanna',
        'email' => 'chan.work@example.com',
        'phone' => '012123123',
        'date_of_birth' => '1995-11-02',
        'gender' => 'male',
        'personal_phone' => '099555666',
        'personal_email' => 'chan.personal@example.com',
        'addresses' => [
            [
                'address_type' => 'temporary',
                'province_id' => $location['province']->id,
                'district_id' => $location['district']->id,
                'commune_id' => $location['commune']->id,
                'village_id' => $location['village']->id,
                'address_line' => 'Rental room',
                'street' => 'Street 2004',
                'house_no' => '3C',
                'postal_code' => '17100',
                'note' => 'Three month lease',
                'is_primary' => true,
            ],
        ],
        'id_type' => 'passport',
        'id_number' => 'P1234567',
        'emergency_contact_name' => 'Kimly',
        'emergency_contact_relationship' => 'spouse',
        'emergency_contact_phone' => '010777888',
        'profile_photo_path' => 'employees/photos/chan-vanna.jpg',
        'hire_date' => '2025-01-10',
        'employment_type' => 'contract',
        'confirmation_date' => '2025-04-10',
        'termination_date' => '2026-12-31',
        'last_working_date' => '2026-12-25',
        'status' => 'active',
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('employee_code', 'EMP000123')
        ->assertJsonPath('personal_phone', '099555666')
        ->assertJsonPath('id_type', 'passport')
        ->assertJsonPath('employment_type', 'contract')
        ->assertJsonPath('branch.name', 'Siem Reap Branch')
        ->assertJsonPath('shift.name', 'Support Shift')
        ->assertJsonPath('addresses.0.address_type', 'temporary')
        ->assertJsonPath('addresses.0.village.name_en', $location['village']->name_en)
        ->assertJsonPath('full_name', 'Chan Vanna');

    $freshEmployee = $employee->fresh();

    expect($response->json('date_of_birth'))->toBe($freshEmployee?->date_of_birth?->toJSON())
        ->and($freshEmployee?->addresses()->count())->toBe(1)
        ->and($freshEmployee?->addresses()->first()?->address_type?->value)->toBe('temporary')
        ->and($freshEmployee?->emergency_contact_relationship?->value)->toBe('spouse')
        ->and($freshEmployee?->last_working_date?->toDateString())->toBe('2026-12-25');
});

it('rejects invalid enum values for employee personal information fields', function () {
    $admin = createEmployeeAdmin();
    $department = Department::query()->create(['name' => 'Compliance']);
    $branch = Branch::factory()->create();
    $shift = Shift::factory()->create();
    $position = Position::query()->create(['title' => 'Officer']);
    $employeeUser = User::factory()->create();

    Passport::actingAs($admin);

    $response = $this->postJson('/api/employees', [
        'user_id' => $employeeUser->id,
        'department_id' => $department->id,
        'branch_id' => $branch->id,
        'position_id' => $position->id,
        'shift_id' => $shift->id,
        'first_name' => 'Mara',
        'last_name' => 'Kim',
        'email' => 'mara.work@example.com',
        'phone' => '012000111',
        'employment_type' => 'invalid_type',
        'gender' => 'invalid_gender',
        'addresses' => [
            [
                'address_type' => 'invalid_address_type',
                'province_id' => 9999,
            ],
        ],
        'id_type' => 'invalid_id',
        'emergency_contact_relationship' => 'invalid_relationship',
        'hire_date' => '2026-04-01',
        'status' => 'active',
    ]);

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'employment_type',
            'gender',
            'addresses.0.address_type',
            'addresses.0.province_id',
            'id_type',
            'emergency_contact_relationship',
        ]);
});

it('rejects employee phone numbers that do not start with zero or are not 9 to 10 digits long', function () {
    $admin = createEmployeeAdmin();
    $department = Department::query()->create(['name' => 'Compliance']);
    $branch = Branch::factory()->create();
    $shift = Shift::factory()->create();
    $position = Position::query()->create(['title' => 'Officer']);
    $employeeUser = User::factory()->create();

    Passport::actingAs($admin);

    $response = $this->postJson('/api/employees', [
        'user_id' => $employeeUser->id,
        'department_id' => $department->id,
        'branch_id' => $branch->id,
        'position_id' => $position->id,
        'shift_id' => $shift->id,
        'first_name' => 'Mara',
        'last_name' => 'Kim',
        'email' => 'mara.phone@example.com',
        'phone' => '889406900',
        'personal_phone' => '01234567',
        'emergency_contact_phone' => '08894069000',
        'emergency_contacts' => [
            [
                'name' => 'Sokha',
                'relationship' => 'friend',
                'phone' => 'A889406900',
                'is_primary' => true,
            ],
        ],
        'hire_date' => '2026-04-01',
        'status' => 'active',
    ]);

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'phone',
            'personal_phone',
            'emergency_contact_phone',
            'emergency_contacts.0.phone',
        ]);
});

function createEmployeeAdmin(): User
{
    $user = User::factory()->create();
    $role = Role::query()->firstOrCreate(
        ['name' => 'admin'],
        ['description' => 'Administrator']
    );

    $user->roles()->syncWithoutDetaching([$role->id]);

    return $user->fresh('roles');
}

/**
 * @return array{province: Province, district: District, commune: Commune, village: Village}
 */
function createLocationHierarchy(): array
{
    $province = Province::query()->create([
        'source_id' => 1,
        'code' => '01',
        'name_kh' => 'ភ្នំពេញ',
        'name_en' => 'Phnom Penh',
    ]);

    $district = District::query()->create([
        'source_id' => 101,
        'code' => '101',
        'province_id' => $province->id,
        'name_kh' => 'ចំការមន',
        'name_en' => 'Chamkar Mon',
        'type' => 'ក្រុង',
    ]);

    $commune = Commune::query()->create([
        'source_id' => 10101,
        'code' => '10101',
        'district_id' => $district->id,
        'name_kh' => 'ទន្លេបាសាក់',
        'name_en' => 'Tonle Basak',
    ]);

    $village = Village::query()->create([
        'source_id' => 1010101,
        'code' => '1010101',
        'commune_id' => $commune->id,
        'name_kh' => 'ភូមិ១',
        'name_en' => 'Village 1',
        'is_not_active' => false,
    ]);

    return [
        'province' => $province,
        'district' => $district,
        'commune' => $commune,
        'village' => $village,
    ];
}
