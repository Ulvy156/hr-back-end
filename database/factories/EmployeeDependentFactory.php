<?php

namespace Database\Factories;

use App\Models\EmployeeDependent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeDependent>
 */
class EmployeeDependentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => null,
            'name' => fake()->name(),
            'relationship' => fake()->randomElement(['spouse', 'child']),
            'date_of_birth' => fake()->dateTimeBetween('-18 years', '-1 year')->format('Y-m-d'),
            'is_active' => true,
            'is_working' => false,
            'is_student' => false,
            'is_claimed_for_tax' => false,
        ];
    }
}
