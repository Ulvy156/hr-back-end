<?php

namespace App\Services\Payroll;

use App\Models\PayrollTaxRule;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

class PayrollTaxRuleResolver
{
    /**
     * @return array{rule: PayrollTaxRule, tax_amount: float}
     */
    public function resolve(float $taxableSalary, CarbonInterface|string $effectiveDate): array
    {
        $effectiveDate = $effectiveDate instanceof CarbonInterface
            ? Carbon::instance($effectiveDate)->toDateString()
            : Carbon::parse($effectiveDate)->toDateString();

        $matchedRules = PayrollTaxRule::query()
            ->activeOn($effectiveDate)
            ->where('min_salary', '<=', $taxableSalary)
            ->where(function ($query) use ($taxableSalary): void {
                $query
                    ->whereNull('max_salary')
                    ->orWhere('max_salary', '>=', $taxableSalary);
            })
            ->orderBy('min_salary')
            ->orderBy('id')
            ->get();

        if ($matchedRules->count() === 0) {
            throw ValidationException::withMessages([
                'tax_rule' => ['No active payroll tax rule matches the calculated salary for the selected month.'],
            ]);
        }

        if ($matchedRules->count() > 1) {
            throw ValidationException::withMessages([
                'tax_rule' => ['Multiple active payroll tax rules match the calculated salary for the selected month.'],
            ]);
        }

        /** @var PayrollTaxRule $rule */
        $rule = $matchedRules->first();

        return [
            'rule' => $rule,
            'tax_amount' => round($taxableSalary * ((float) $rule->rate_percentage) / 100, 2),
        ];
    }
}
