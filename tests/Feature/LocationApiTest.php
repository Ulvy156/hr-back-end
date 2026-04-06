<?php

use App\Models\Commune;
use App\Models\District;
use App\Models\Province;
use App\Models\Role;
use App\Models\User;
use App\Models\Village;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

it('lists provinces for employees with optional search', function () {
    $employee = createLocationActor('employee');

    $firstProvince = Province::query()->create([
        'source_id' => 1,
        'code' => '01',
        'name_kh' => 'ភ្នំពេញ',
        'name_en' => 'Phnom Penh',
    ]);
    Province::query()->create([
        'source_id' => 2,
        'code' => '02',
        'name_kh' => 'កំពង់ចាម',
        'name_en' => 'Kampong Cham',
    ]);

    Passport::actingAs($employee);

    $this->getJson('/api/locations/provinces?search=phnom')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $firstProvince->id)
        ->assertJsonPath('data.0.name_en', 'Phnom Penh');
});

it('lists districts communes and villages filtered by their parent ids', function () {
    $hr = createLocationActor('hr');

    $provinceA = Province::query()->create([
        'source_id' => 1,
        'code' => '01',
        'name_kh' => 'ភ្នំពេញ',
        'name_en' => 'Phnom Penh',
    ]);
    $provinceB = Province::query()->create([
        'source_id' => 2,
        'code' => '02',
        'name_kh' => 'កណ្ដាល',
        'name_en' => 'Kandal',
    ]);
    $districtA = District::query()->create([
        'source_id' => 101,
        'code' => '101',
        'province_id' => $provinceA->id,
        'name_kh' => 'ចំការមន',
        'name_en' => 'Chamkar Mon',
        'type' => 'ក្រុង',
    ]);
    District::query()->create([
        'source_id' => 201,
        'code' => '201',
        'province_id' => $provinceB->id,
        'name_kh' => 'តាខ្មៅ',
        'name_en' => 'Ta Khmau',
        'type' => 'ក្រុង',
    ]);
    $communeA = Commune::query()->create([
        'source_id' => 10101,
        'code' => '10101',
        'district_id' => $districtA->id,
        'name_kh' => 'ទន្លេបាសាក់',
        'name_en' => 'Tonle Basak',
    ]);
    Commune::query()->create([
        'source_id' => 10102,
        'code' => '10102',
        'district_id' => $districtA->id,
        'name_kh' => 'បឹងកេងកងទី១',
        'name_en' => 'Boeng Keng Kang Ti Muoy',
    ]);
    $villageA = Village::query()->create([
        'source_id' => 1010101,
        'code' => '1010101',
        'commune_id' => $communeA->id,
        'name_kh' => 'ភូមិ១',
        'name_en' => 'Village 1',
        'is_not_active' => false,
    ]);
    Village::query()->create([
        'source_id' => 1010102,
        'code' => '1010102',
        'commune_id' => $communeA->id,
        'name_kh' => 'ភូមិ២',
        'name_en' => 'Village 2',
        'is_not_active' => false,
    ]);

    Passport::actingAs($hr);

    $this->getJson('/api/locations/districts?province_id='.$provinceA->id.'&search=chamkar')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $districtA->id)
        ->assertJsonPath('data.0.province_id', $provinceA->id);

    $this->getJson('/api/locations/communes?district_id='.$districtA->id.'&search=tonle')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $communeA->id)
        ->assertJsonPath('data.0.district_id', $districtA->id);

    $this->getJson('/api/locations/villages?commune_id='.$communeA->id.'&search=village 1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $villageA->id)
        ->assertJsonPath('data.0.commune_id', $communeA->id);
});

it('forbids users without an allowed role from listing locations', function () {
    $user = User::factory()->create();

    Passport::actingAs($user);

    $this->getJson('/api/locations/provinces')
        ->assertForbidden();
});

function createLocationActor(string $roleName): User
{
    $user = User::factory()->create();
    $role = Role::query()->firstOrCreate(
        ['name' => $roleName],
        ['description' => ucfirst($roleName)],
    );

    $user->roles()->syncWithoutDetaching([$role->id]);

    return $user->fresh('roles');
}
