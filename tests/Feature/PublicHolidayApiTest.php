<?php

use App\Models\PublicHoliday;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

it('lists public holidays from the database and supports filtering by year', function () {
    $employee = createPublicHolidayActor('employee');

    $kh2026First = PublicHoliday::factory()->create([
        'name' => 'Khmer New Year',
        'holiday_date' => '2026-04-14',
        'year' => 2026,
        'country_code' => 'KH',
        'is_paid' => true,
    ]);
    $kh2026Second = PublicHoliday::factory()->create([
        'name' => 'Visak Bochea Day',
        'holiday_date' => '2026-05-01',
        'year' => 2026,
        'country_code' => 'KH',
        'is_paid' => true,
    ]);
    PublicHoliday::factory()->create([
        'name' => 'Khmer New Year 2025',
        'holiday_date' => '2025-04-14',
        'year' => 2025,
        'country_code' => 'KH',
        'is_paid' => true,
    ]);
    PublicHoliday::factory()->create([
        'name' => 'Different Country Holiday',
        'holiday_date' => '2026-04-01',
        'year' => 2026,
        'country_code' => 'US',
        'is_paid' => false,
    ]);

    Passport::actingAs($employee);

    $this->getJson('/api/leave/public-holidays?year=2026')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $kh2026First->id)
        ->assertJsonPath('data.0.name', 'Khmer New Year')
        ->assertJsonPath('data.0.holiday_date', '2026-04-14')
        ->assertJsonPath('data.0.year', 2026)
        ->assertJsonPath('data.0.country_code', 'KH')
        ->assertJsonPath('data.0.is_paid', true)
        ->assertJsonPath('data.1.id', $kh2026Second->id)
        ->assertJsonMissing([
            'name' => 'Khmer New Year 2025',
        ])
        ->assertJsonMissing([
            'name' => 'Different Country Holiday',
        ]);
});

it('validates the optional year filter when listing public holidays', function () {
    $employee = createPublicHolidayActor('employee');

    Passport::actingAs($employee);

    $this->getJson('/api/leave/public-holidays?year=abc')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['year']);
});

it('forbids users without an allowed role from listing public holidays', function () {
    $user = User::factory()->create();

    Passport::actingAs($user);

    $this->getJson('/api/leave/public-holidays')
        ->assertForbidden();
});

function createPublicHolidayActor(string $roleName): User
{
    $user = User::factory()->create();
    $role = Role::query()->firstOrCreate(
        ['name' => $roleName],
        ['description' => ucfirst($roleName)],
    );

    $user->roles()->syncWithoutDetaching([$role->id]);

    return $user->fresh('roles');
}
