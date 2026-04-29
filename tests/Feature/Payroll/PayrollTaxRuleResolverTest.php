<?php

use App\Models\PayrollTaxRule;
use App\Services\Payroll\PayrollTaxRuleResolver;
use Database\Seeders\PayrollTaxRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('matches the zero tax bracket for april 2026 salaries', function () {
    $this->seed(PayrollTaxRuleSeeder::class);

    $result = app(PayrollTaxRuleResolver::class)->resolve(1500000.00, '2026-04-30');

    expect($result['rule']->name)->toBe('Cambodia Tax Bracket 0%')
        ->and($result['rule']->rate_percentage)->toBe('0.00')
        ->and($result['tax_amount'])->toBe(0.0);
});

it('matches a taxable bracket for april 2026 salaries', function () {
    $this->seed(PayrollTaxRuleSeeder::class);

    $result = app(PayrollTaxRuleResolver::class)->resolve(2500000.00, '2026-04-30');

    expect($result['rule']->name)->toBe('Cambodia Tax Bracket 10%')
        ->and($result['rule']->rate_percentage)->toBe('10.00')
        ->and($result['tax_amount'])->toBe(250000.0);
});

it('matches the open ended top bracket for high salaries', function () {
    $this->seed(PayrollTaxRuleSeeder::class);

    $result = app(PayrollTaxRuleResolver::class)->resolve(13000000.00, '2026-04-30');

    expect($result['rule']->name)->toBe('Cambodia Tax Bracket 20%')
        ->and($result['rule']->max_salary)->toBeNull()
        ->and($result['tax_amount'])->toBe(2600000.0);
});

it('ignores inactive rules when resolving payroll tax', function () {
    createResolverTaxRule([
        'name' => 'Inactive Matching Rule',
        'rate_percentage' => '20.00',
        'min_salary' => '0.00',
        'max_salary' => '5000000.00',
        'is_active' => false,
        'effective_from' => '2026-01-01',
        'effective_to' => null,
    ]);
    createResolverTaxRule([
        'name' => 'Active Matching Rule',
        'rate_percentage' => '5.00',
        'min_salary' => '0.00',
        'max_salary' => '5000000.00',
        'is_active' => true,
        'effective_from' => '2026-01-01',
        'effective_to' => null,
    ]);

    $result = app(PayrollTaxRuleResolver::class)->resolve(1000000.00, '2026-04-30');

    expect($result['rule']->name)->toBe('Active Matching Rule')
        ->and($result['tax_amount'])->toBe(50000.0);
});

it('ignores rules outside the effective date range', function () {
    createResolverTaxRule([
        'name' => 'Expired Matching Rule',
        'rate_percentage' => '20.00',
        'min_salary' => '0.00',
        'max_salary' => '5000000.00',
        'is_active' => true,
        'effective_from' => '2025-01-01',
        'effective_to' => '2026-03-31',
    ]);
    createResolverTaxRule([
        'name' => 'Current Matching Rule',
        'rate_percentage' => '5.00',
        'min_salary' => '0.00',
        'max_salary' => '5000000.00',
        'is_active' => true,
        'effective_from' => '2026-04-01',
        'effective_to' => null,
    ]);

    $result = app(PayrollTaxRuleResolver::class)->resolve(1000000.00, '2026-04-30');

    expect($result['rule']->name)->toBe('Current Matching Rule')
        ->and($result['tax_amount'])->toBe(50000.0);
});

it('throws when no active rule covers the salary and date', function () {
    createResolverTaxRule([
        'name' => 'Future Rule',
        'rate_percentage' => '5.00',
        'min_salary' => '0.00',
        'max_salary' => '5000000.00',
        'is_active' => true,
        'effective_from' => '2026-05-01',
        'effective_to' => null,
    ]);

    try {
        app(PayrollTaxRuleResolver::class)->resolve(1000000.00, '2026-04-30');

        $this->fail('Expected a validation exception when no active payroll tax rule matches.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('tax_rule')
            ->and($exception->errors()['tax_rule'][0])->toBe('No active payroll tax rule matches the calculated salary for the selected month.');
    }
});

/**
 * @param  array{
 *     name?: string,
 *     rate_percentage?: string,
 *     min_salary?: string,
 *     max_salary?: string|null,
 *     is_active?: bool,
 *     effective_from?: string,
 *     effective_to?: string|null
 * }  $overrides
 */
function createResolverTaxRule(array $overrides = []): PayrollTaxRule
{
    return PayrollTaxRule::query()->create([
        'name' => $overrides['name'] ?? 'Resolver Tax Rule',
        'rate_percentage' => $overrides['rate_percentage'] ?? '0.00',
        'min_salary' => $overrides['min_salary'] ?? '0.00',
        'max_salary' => $overrides['max_salary'] ?? null,
        'is_active' => $overrides['is_active'] ?? true,
        'effective_from' => $overrides['effective_from'] ?? '2026-01-01',
        'effective_to' => $overrides['effective_to'] ?? null,
    ]);
}
