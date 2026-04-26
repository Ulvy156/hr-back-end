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
                'name' => 'Tax Exempt',
                'rate_percentage' => '0.00',
                'min_salary' => '0.00',
                'max_salary' => '1000.00',
                'is_active' => true,
                'effective_from' => '2026-01-01',
                'effective_to' => null,
            ],
            [
                'name' => 'Standard Tax',
                'rate_percentage' => '5.00',
                'min_salary' => '1000.01',
                'max_salary' => '3000.00',
                'is_active' => true,
                'effective_from' => '2026-01-01',
                'effective_to' => null,
            ],
            [
                'name' => 'Executive Tax',
                'rate_percentage' => '10.00',
                'min_salary' => '3000.01',
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
