<?php

namespace Database\Factories;

use App\Models\Shift;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shift>
 */
class ShiftFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->randomElement(['Morning Shift', 'Office Shift', 'Support Shift']),
            'code' => fake()->unique()->lexify('SH-????'),
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
            'late_grace_minutes' => fake()->numberBetween(0, 30),
            'is_active' => true,
        ];
    }
}
