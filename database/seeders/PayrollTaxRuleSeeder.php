<?php

namespace Database\Seeders;

use App\Models\PayrollTaxRule;
use Illuminate\Database\Seeder;

class PayrollTaxRuleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        collect([
            [
                'name' => 'Cambodia Tax Bracket 0%',
                'rate_percentage' => '0.00',
                'min_salary' => '0.00',
                'max_salary' => '1500000.00',
                'is_active' => true,
                'effective_from' => '2026-01-01',
                'effective_to' => null,
            ],
            [
                'name' => 'Cambodia Tax Bracket 5%',
                'rate_percentage' => '5.00',
                'min_salary' => '1500000.01',
                'max_salary' => '2000000.00',
                'is_active' => true,
                'effective_from' => '2026-01-01',
                'effective_to' => null,
            ],
            [
                'name' => 'Cambodia Tax Bracket 10%',
                'rate_percentage' => '10.00',
                'min_salary' => '2000000.01',
                'max_salary' => '8500000.00',
                'is_active' => true,
                'effective_from' => '2026-01-01',
                'effective_to' => null,
            ],
            [
                'name' => 'Cambodia Tax Bracket 15%',
                'rate_percentage' => '15.00',
                'min_salary' => '8500000.01',
                'max_salary' => '12500000.00',
                'is_active' => true,
                'effective_from' => '2026-01-01',
                'effective_to' => null,
            ],
            [
                'name' => 'Cambodia Tax Bracket 20%',
                'rate_percentage' => '20.00',
                'min_salary' => '12500000.01',
                'max_salary' => null,
                'is_active' => true,
                'effective_from' => '2026-01-01',
                'effective_to' => null,
            ],
        ])->each(function (array $rule): void {
            PayrollTaxRule::query()->updateOrCreate(
                [
                    'name' => $rule['name'],
                    'effective_from' => $rule['effective_from'],
                ],
                $rule,
            );
        });
    }
}
