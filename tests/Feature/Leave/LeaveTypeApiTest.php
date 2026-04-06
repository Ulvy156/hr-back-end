<?php

use App\LeaveTypeCode;
use App\LeaveTypeGenderRestriction;
use App\Models\LeaveType;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\LeaveTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

it('seeds cambodia leave types idempotently by stable code', function () {
    $this->seed(LeaveTypeSeeder::class);
    $this->seed(LeaveTypeSeeder::class);

    expect(LeaveType::query()->count())->toBe(5);

    $annual = LeaveType::query()
        ->where('code', LeaveTypeCode::Annual->value)
        ->firstOrFail();

    expect($annual->code)->toBe(LeaveTypeCode::Annual->value)
        ->and($annual->is_paid)->toBeTrue()
        ->and($annual->requires_balance)->toBeTrue()
        ->and($annual->auto_exclude_public_holidays)->toBeTrue()
        ->and($annual->auto_exclude_weekends)->toBeTrue()
        ->and($annual->gender_restriction)->toBe(LeaveTypeGenderRestriction::None)
        ->and($annual->min_service_days)->toBe(365)
        ->and($annual->metadata)->toMatchArray([
            'law_defaults' => [
                'accrual_days_per_month' => 1.5,
                'seniority_bonus_day_every_service_years' => 3,
                'seniority_bonus_days_added' => 1,
                'usable_after_service_days' => 365,
                'exclude_paid_public_holidays_from_deduction' => true,
                'exclude_sick_leave_from_annual_leave_deduction' => true,
            ],
        ]);
});

it('lists only active leave types ordered by sort order and id', function () {
    $employee = createLeaveTypeActor('employee');

    $firstType = LeaveType::factory()->create([
        'code' => 'bereavement',
        'name' => 'Bereavement Leave',
        'sort_order' => 5,
    ]);
    $inactiveType = LeaveType::factory()->inactive()->create([
        'code' => 'inactive_type',
        'name' => 'Inactive Leave',
        'sort_order' => 1,
    ]);
    $secondType = LeaveType::factory()->create([
        'code' => 'study',
        'name' => 'Study Leave',
        'sort_order' => 5,
    ]);
    $thirdType = LeaveType::factory()->create([
        'code' => 'compassionate',
        'name' => 'Compassionate Leave',
        'sort_order' => 20,
    ]);

    Passport::actingAs($employee);

    $this->getJson('/api/leave/types')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.id', $firstType->id)
        ->assertJsonPath('data.0.code', 'bereavement')
        ->assertJsonPath('data.1.id', $secondType->id)
        ->assertJsonPath('data.2.id', $thirdType->id)
        ->assertJsonMissing([
            'id' => $inactiveType->id,
            'code' => 'inactive_type',
        ]);
});

it('forbids users without an allowed role from listing leave types', function () {
    $user = User::factory()->create();

    Passport::actingAs($user);

    $this->getJson('/api/leave/types')
        ->assertForbidden();
});

function createLeaveTypeActor(string $roleName): User
{
    $user = User::factory()->create();
    $role = Role::query()->firstOrCreate(
        ['name' => $roleName],
        ['description' => ucfirst($roleName)],
    );

    $user->roles()->syncWithoutDetaching([$role->id]);

    return $user->fresh('roles');
}
