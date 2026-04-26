<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach ($this->users() as $attributes) {
            $role = Role::query()->where('name', $attributes['role'])->firstOrFail();

            $user = User::query()->firstOrCreate(
                ['email' => $attributes['email']],
                [
                    'name' => $attributes['name'],
                    'password' => Hash::make('password'),
                ],
            );

            $user->forceFill([
                'name' => $attributes['name'],
                'password' => Hash::make('password'),
            ])->save();

            $user->syncRoles([]);
            $user->assignRole($role);
        }
    }

    /**
     * @return array<int, array{name: string, email: string, role: string}>
     */
    private function users(): array
    {
        return [
            [
                'name' => 'Admin User',
                'email' => 'admin@example.com',
                'role' => 'admin',
            ],
            [
                'name' => 'HR User',
                'email' => 'hr@example.com',
                'role' => 'hr',
            ],
            [
                'name' => 'Manager User',
                'email' => 'manager@example.com',
                'role' => 'manager',
            ],
            [
                'name' => 'Employee User',
                'email' => 'employee@example.com',
                'role' => 'employee',
            ],
        ];
    }
}
