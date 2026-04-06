<?php

use App\Models\Commune;
use App\Models\Department;
use App\Models\District;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Province;
use App\Models\Role;
use App\Models\User;
use App\Models\Village;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

it('allows admin to create list update and delete employee addresses independently', function () {
    $admin = createEmployeeAddressActor('admin');
    $employee = createEmployeeForAddressApi();
    $location = createAddressLocationHierarchy();

    Passport::actingAs($admin);

    $createResponse = $this->postJson("/api/employees/{$employee->id}/addresses", [
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
    ]);

    $addressId = $createResponse->json('id');

    $createResponse
        ->assertCreated()
        ->assertJsonPath('employee_id', null)
        ->assertJsonPath('address_type', 'current')
        ->assertJsonPath('province.name_en', 'Phnom Penh')
        ->assertJsonPath('is_primary', true);

    $this->getJson("/api/employees/{$employee->id}/addresses")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $addressId)
        ->assertJsonPath('data.0.village.name_en', 'Village 1');

    $this->putJson("/api/employees/{$employee->id}/addresses/{$addressId}", [
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
        'is_primary' => false,
    ])
        ->assertOk()
        ->assertJsonPath('address_type', 'temporary')
        ->assertJsonPath('address_line', 'Rental room')
        ->assertJsonPath('is_primary', true);

    $this->deleteJson("/api/employees/{$employee->id}/addresses/{$addressId}")
        ->assertNoContent();

    $this->getJson("/api/employees/{$employee->id}/addresses")
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('rejects invalid employee address type values', function () {
    $admin = createEmployeeAddressActor('admin');
    $employee = createEmployeeForAddressApi();

    Passport::actingAs($admin);

    $this->postJson("/api/employees/{$employee->id}/addresses", [
        'address_type' => 'invalid',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['address_type']);
});

it('forbids employees from managing employee addresses independently', function () {
    $employeeActor = createEmployeeAddressActor('employee');
    $employee = createEmployeeForAddressApi();

    Passport::actingAs($employeeActor);

    $this->getJson("/api/employees/{$employee->id}/addresses")
        ->assertForbidden();
});

function createEmployeeAddressActor(string $roleName): User
{
    $user = User::factory()->create();
    $role = Role::query()->firstOrCreate(
        ['name' => $roleName],
        ['description' => ucfirst($roleName)],
    );

    $user->roles()->syncWithoutDetaching([$role->id]);

    return $user->fresh('roles');
}

function createEmployeeForAddressApi(): Employee
{
    $department = Department::query()->create(['name' => 'Operations']);
    $position = Position::query()->create(['title' => 'Coordinator']);
    $user = User::factory()->create();

    return Employee::query()->create([
        'user_id' => $user->id,
        'department_id' => $department->id,
        'current_position_id' => $position->id,
        'first_name' => 'Dara',
        'last_name' => 'Sok',
        'email' => fake()->unique()->safeEmail(),
        'phone' => '012345678',
        'hire_date' => '2024-01-15',
        'status' => 'active',
    ]);
}

/**
 * @return array{province: Province, district: District, commune: Commune, village: Village}
 */
function createAddressLocationHierarchy(): array
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
