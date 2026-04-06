<?php

use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

it('lists positions for hr users with optional case-insensitive search', function () {
    $hr = createPositionActor('hr');

    $analyst = Position::query()->create(['title' => 'Analyst']);
    Position::query()->create(['title' => 'Coordinator']);
    Position::query()->create(['title' => 'Senior Analyst']);

    Passport::actingAs($hr);

    $this->getJson('/api/positions?search=analyst')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $analyst->id)
        ->assertJsonPath('data.0.title', 'Analyst')
        ->assertJsonPath('data.1.title', 'Senior Analyst');
});

it('forbids employees from listing positions', function () {
    $employee = createPositionActor('employee');

    Passport::actingAs($employee);

    $this->getJson('/api/positions')
        ->assertForbidden();
});

function createPositionActor(string $roleName): User
{
    $user = User::factory()->create();
    $role = Role::query()->firstOrCreate(
        ['name' => $roleName],
        ['description' => ucfirst($roleName)],
    );

    $user->roles()->syncWithoutDetaching([$role->id]);

    return $user->fresh('roles');
}
