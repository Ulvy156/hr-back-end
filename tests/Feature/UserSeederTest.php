<?php

use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('seeds demo users with the correct roles idempotently', function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $this->seed(UserSeeder::class);
    $this->seed(UserSeeder::class);

    $users = User::query()
        ->whereIn('email', [
            'admin@example.com',
            'hr@example.com',
            'manager@example.com',
            'employee@example.com',
        ])
        ->with('roles')
        ->orderBy('email')
        ->get();

    expect($users)->toHaveCount(4)
        ->and($users->pluck('email')->all())->toBe([
            'admin@example.com',
            'employee@example.com',
            'hr@example.com',
            'manager@example.com',
        ]);

    $roleMap = $users->mapWithKeys(fn (User $user): array => [
        $user->email => $user->roles->pluck('name')->all(),
    ]);

    expect($roleMap['admin@example.com'])->toBe(['admin'])
        ->and($roleMap['hr@example.com'])->toBe(['hr'])
        ->and($roleMap['manager@example.com'])->toBe(['manager'])
        ->and($roleMap['employee@example.com'])->toBe(['employee'])
        ->and(Hash::check('password', $users->firstWhere('email', 'admin@example.com')->password))->toBeTrue();
});
