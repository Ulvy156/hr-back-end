<?php

namespace Database\Factories;

use App\EmployeeAddressType;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeAddress;
use App\Models\Position;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeAddress>
 */
class EmployeeAddressFactory extends Factory
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
            'address_type' => fake()->randomElement(array_column(EmployeeAddressType::cases(), 'value')),
            'province_id' => null,
            'district_id' => null,
            'commune_id' => null,
            'village_id' => null,
            'address_line' => fake()->streetAddress(),
            'street' => fake()->streetName(),
            'house_no' => fake()->buildingNumber(),
            'postal_code' => fake()->postcode(),
            'note' => fake()->optional()->sentence(),
            'is_primary' => false,
        ];
    }
}
