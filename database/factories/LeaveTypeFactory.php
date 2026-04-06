<?php

namespace Database\Factories;

use App\LeaveTypeCode;
use App\LeaveTypeGenderRestriction;
use App\Models\LeaveType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeaveType>
 */
class LeaveTypeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->slug(2),
            'name' => fake()->unique()->words(2, true),
            'description' => fake()->sentence(),
            'is_paid' => fake()->boolean(),
            'requires_balance' => false,
            'requires_attachment' => false,
            'requires_medical_certificate' => false,
            'auto_exclude_public_holidays' => fake()->boolean(),
            'auto_exclude_weekends' => fake()->boolean(),
            'gender_restriction' => fake()->randomElement([
                LeaveTypeGenderRestriction::None->value,
                LeaveTypeGenderRestriction::Male->value,
                LeaveTypeGenderRestriction::Female->value,
                null,
            ]),
            'min_service_days' => fake()->optional()->numberBetween(30, 730),
            'max_days_per_request' => fake()->optional()->numberBetween(1, 120),
            'max_days_per_year' => fake()->optional()->numberBetween(1, 365),
            'is_active' => true,
            'sort_order' => fake()->numberBetween(0, 100),
            'metadata' => null,
        ];
    }

    public function annual(): static
    {
        return $this->state(fn (): array => [
            'code' => LeaveTypeCode::Annual->value,
            'name' => 'Annual Leave',
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => [
            'is_active' => false,
        ]);
    }
}
