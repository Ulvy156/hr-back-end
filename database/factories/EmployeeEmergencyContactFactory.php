<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeEmergencyContact;
use App\Models\Position;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeEmergencyContact>
 */
class EmployeeEmergencyContactFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::query()->inRandomOrder()->value('id') ?? Employee::query()->create([
                'user_id' => User::factory()->create()->id,
                'department_id' => Department::query()->create(['name' => fake()->unique()->company()])->id,
                'current_position_id' => Position::query()->create(['title' => fake()->unique()->jobTitle()])->id,
                'first_name' => fake()->firstName(),
                'last_name' => fake()->lastName(),
                'email' => fake()->unique()->safeEmail(),
                'phone' => '0'.fake()->numerify('#########'),
                'hire_date' => now()->subYear()->toDateString(),
                'status' => 'active',
            ])->id,
            'name' => fake()->name(),
            'relationship' => fake()->randomElement(['parent', 'sibling', 'spouse', 'friend']),
            'phone' => '0'.fake()->numerify('#########'),
            'email' => fake()->optional()->safeEmail(),
            'is_primary' => false,
        ];
    }
}
