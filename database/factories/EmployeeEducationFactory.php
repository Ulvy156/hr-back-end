<?php

namespace Database\Factories;

use App\EmployeeEducationLevel;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeEducation;
use App\Models\Position;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeEducation>
 */
class EmployeeEducationFactory extends Factory
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
            'institution_name' => fake()->company().' University',
            'education_level' => fake()->randomElement(array_column(EmployeeEducationLevel::cases(), 'value')),
            'degree' => fake()->randomElement(['Computer Science', 'Business Administration', 'Accounting']),
            'field_of_study' => fake()->randomElement(['Computer Science', 'Human Resource Management', 'Finance']),
            'start_date' => now()->subYears(6)->startOfYear()->toDateString(),
            'end_date' => now()->subYears(2)->endOfYear()->toDateString(),
            'graduation_year' => (int) now()->subYears(2)->format('Y'),
            'grade' => fake()->randomElement(['A', 'B+', '3.5 GPA']),
            'description' => fake()->sentence(),
        ];
    }
}
