<?php

namespace App\Services\Payroll;

use App\LeaveTypeCode;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Services\Leave\LeaveRequestDurationType;
use App\Services\Leave\LeaveRequestStatus;
use App\Services\Overtime\OvertimeRequestStatus;
use App\Services\Overtime\OvertimeType;
use App\Services\PublicHoliday\PublicHolidayService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class PayrollCalculationService
{
    private const DAILY_WORKING_HOURS = 8;

    private const OT_MULTIPLIER_NORMAL = 1.5;

    private const OT_MULTIPLIER_WEEKEND = 2.0;

    private const OT_MULTIPLIER_HOLIDAY = 2.0;

    public function __construct(
        private EmployeeSalaryResolver $employeeSalaryResolver,
        private PayrollTaxRuleResolver $payrollTaxRuleResolver,
        private PublicHolidayService $publicHolidayService,
    ) {}

    /**
     * @return array{
     *     employee_id: int,
     *     payroll_month: string,
     *     month_start: string,
     *     month_end: string,
     *     calculation_window_start: string|null,
     *     calculation_window_end: string|null,
     *     salary_source: 'employee_salaries'|'employee_positions_fallback',
     *     employee_salary_id: int|null,
     *     base_salary: float,
     *     prorated_base_salary: float,
     *     hourly_rate: float,
     *     daily_rate: float,
     *     eligible_working_days: int,
     *     company_working_days: int,
     *     monthly_working_hours: int,
     *     overtime_normal_hours: float,
     *     overtime_weekend_hours: float,
     *     overtime_holiday_hours: float,
     *     overtime_pay: float,
     *     unpaid_leave_units: float,
     *     unpaid_leave_deduction: float,
     *     dependents_count: int,
     *     dependent_allowance: float,
     *     taxable_salary: float,
     *     tax_amount: float,
     *     gross_net_salary: float,
     *     net_salary: float
     * }
     */
    public function calculateForEmployee(Employee $employee, Carbon|string $payrollMonth): array
    {
        [$monthStart, $monthEnd] = $this->normalizePayrollMonth($payrollMonth);
        $holidayDates = $this->holidayDateLookup($monthStart, $monthEnd);
        $companyWorkingDates = $this->workingDateStringsBetween($monthStart, $monthEnd, $holidayDates);
        $companyWorkingDays = $companyWorkingDates->count();

        if ($companyWorkingDays < 1) {
            throw ValidationException::withMessages([
                'payroll_month' => ['The selected month has no company working days.'],
            ]);
        }

        $calculationWindow = $this->employmentWindowWithinMonth($employee, $monthStart, $monthEnd);
        $salaryReferenceDate = $calculationWindow['end'] ?? $monthEnd;
        $resolvedSalary = $this->employeeSalaryResolver->resolveForDate($employee, $salaryReferenceDate);

        if (($resolvedSalary['salary_source'] ?? 'missing') === 'missing' || $resolvedSalary['amount'] === null) {
            throw ValidationException::withMessages([
                'salary' => ['No valid salary found for selected month.'],
            ]);
        }

        $baseSalary = $this->roundMoney((float) $resolvedSalary['amount']);
        $monthlyWorkingHours = $companyWorkingDays * self::DAILY_WORKING_HOURS;
        $hourlyRate = $this->roundRate($baseSalary / $monthlyWorkingHours);
        $dailyRate = $this->roundRate($baseSalary / $companyWorkingDays);

        $eligibleWorkingDays = $this->eligibleWorkingDays(
            $companyWorkingDates,
            $calculationWindow['start'],
            $calculationWindow['end'],
        );
        $proratedBaseSalary = $this->roundMoney(
            $baseSalary * $eligibleWorkingDays / $companyWorkingDays
        );

        $overtime = $this->overtimeBreakdown(
            $employee,
            $calculationWindow['start'],
            $calculationWindow['end'],
            $hourlyRate,
        );
        $unpaidLeaveUnits = $this->unpaidLeaveUnits(
            $employee,
            $calculationWindow['start'],
            $calculationWindow['end'],
            $holidayDates,
        );
        $unpaidLeaveDeduction = $this->roundMoney($unpaidLeaveUnits * $dailyRate);
        $dependentsCount = $this->qualifiedTaxDependentsCount($employee, $monthEnd);
        $dependentAllowance = $this->dependentAllowance();
        $taxableSalary = $this->roundMoney(max(
            $proratedBaseSalary - ($dependentsCount * $dependentAllowance),
            0
        ));
        $taxBreakdown = $this->payrollTaxRuleResolver->resolve($taxableSalary, $monthEnd);
        $taxAmount = $this->roundMoney($taxBreakdown['tax_amount']);
        $grossNetSalary = $this->roundMoney(
            $proratedBaseSalary + $overtime['pay'] - $unpaidLeaveDeduction - $taxAmount
        );
        $netSalary = $this->roundMoney(max($grossNetSalary, 0));

        return [
            'employee_id' => $employee->id,
            'payroll_month' => $monthStart->format('Y-m'),
            'month_start' => $monthStart->toDateString(),
            'month_end' => $monthEnd->toDateString(),
            'calculation_window_start' => $calculationWindow['start']?->toDateString(),
            'calculation_window_end' => $calculationWindow['end']?->toDateString(),
            'salary_source' => $resolvedSalary['salary_source'],
            'employee_salary_id' => $resolvedSalary['employee_salary_id'],
            'base_salary' => $baseSalary,
            'prorated_base_salary' => $proratedBaseSalary,
            'hourly_rate' => $hourlyRate,
            'daily_rate' => $dailyRate,
            'eligible_working_days' => $eligibleWorkingDays,
            'company_working_days' => $companyWorkingDays,
            'monthly_working_hours' => $monthlyWorkingHours,
            'overtime_normal_hours' => $overtime['normal_hours'],
            'overtime_weekend_hours' => $overtime['weekend_hours'],
            'overtime_holiday_hours' => $overtime['holiday_hours'],
            'overtime_pay' => $overtime['pay'],
            'unpaid_leave_units' => $this->roundHours($unpaidLeaveUnits),
            'unpaid_leave_deduction' => $unpaidLeaveDeduction,
            'dependents_count' => $dependentsCount,
            'dependent_allowance' => $dependentAllowance,
            'taxable_salary' => $taxableSalary,
            'tax_amount' => $taxAmount,
            'gross_net_salary' => $grossNetSalary,
            'net_salary' => $netSalary,
        ];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function normalizePayrollMonth(Carbon|string $payrollMonth): array
    {
        if ($payrollMonth instanceof Carbon) {
            $monthStart = $payrollMonth->copy()->startOfMonth();
        } elseif (preg_match('/^\d{4}-\d{2}$/', $payrollMonth) === 1) {
            $monthStart = Carbon::createFromFormat('Y-m', $payrollMonth)->startOfMonth();
        } else {
            $monthStart = Carbon::parse($payrollMonth)->startOfMonth();
        }

        return [$monthStart, $monthStart->copy()->endOfMonth()];
    }

    /**
     * @return array<string, true>
     */
    private function holidayDateLookup(CarbonInterface $startDate, CarbonInterface $endDate): array
    {
        return array_fill_keys(
            $this->publicHolidayService->holidayDatesBetween($startDate, $endDate),
            true,
        );
    }

    /**
     * @param  array<string, true>  $holidayDates
     * @return Collection<int, string>
     */
    private function workingDateStringsBetween(
        CarbonInterface $startDate,
        CarbonInterface $endDate,
        array $holidayDates,
    ): Collection {
        return collect(CarbonPeriod::create($startDate, $endDate))
            ->map(fn (CarbonInterface $date): string => Carbon::instance($date)->toDateString())
            ->filter(function (string $date) use ($holidayDates): bool {
                $carbonDate = Carbon::parse($date);

                return ! $carbonDate->isWeekend() && ! array_key_exists($date, $holidayDates);
            })
            ->values();
    }

    /**
     * @return array{start: Carbon|null, end: Carbon|null}
     */
    private function employmentWindowWithinMonth(
        Employee $employee,
        CarbonInterface $monthStart,
        CarbonInterface $monthEnd,
    ): array {
        $employmentStart = $employee->hire_date?->copy()->startOfDay() ?? $monthStart->copy()->startOfDay();
        $employmentEnd = $employee->last_working_date?->copy()->startOfDay()
            ?? $employee->termination_date?->copy()->startOfDay()
            ?? $monthEnd->copy()->startOfDay();

        $windowStart = $employmentStart->greaterThan($monthStart) ? $employmentStart : $monthStart->copy()->startOfDay();
        $windowEnd = $employmentEnd->lessThan($monthEnd) ? $employmentEnd : $monthEnd->copy()->startOfDay();

        if ($windowStart->gt($windowEnd)) {
            return [
                'start' => null,
                'end' => null,
            ];
        }

        return [
            'start' => $windowStart,
            'end' => $windowEnd,
        ];
    }

    /**
     * @param  Collection<int, string>  $companyWorkingDates
     */
    private function eligibleWorkingDays(
        Collection $companyWorkingDates,
        ?CarbonInterface $windowStart,
        ?CarbonInterface $windowEnd,
    ): int {
        if (! $windowStart instanceof CarbonInterface || ! $windowEnd instanceof CarbonInterface) {
            return 0;
        }

        return $companyWorkingDates
            ->filter(fn (string $date): bool => $date >= $windowStart->toDateString() && $date <= $windowEnd->toDateString())
            ->count();
    }

    /**
     * @return array{normal_hours: float, weekend_hours: float, holiday_hours: float, pay: float}
     */
    private function overtimeBreakdown(
        Employee $employee,
        ?CarbonInterface $windowStart,
        ?CarbonInterface $windowEnd,
        float $hourlyRate,
    ): array {
        if (! $windowStart instanceof CarbonInterface || ! $windowEnd instanceof CarbonInterface) {
            return [
                'normal_hours' => 0.0,
                'weekend_hours' => 0.0,
                'holiday_hours' => 0.0,
                'pay' => 0.0,
            ];
        }

        $normalHours = 0.0;
        $weekendHours = 0.0;
        $holidayHours = 0.0;
        $pay = 0.0;

        OvertimeRequest::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('overtime_date', [$windowStart->toDateString(), $windowEnd->toDateString()])
            ->where('status', OvertimeRequestStatus::Approved)
            ->where('minutes', '>', 0)
            ->orderBy('overtime_date')
            ->each(function (OvertimeRequest $overtimeRequest) use (&$holidayHours, &$normalHours, &$pay, &$weekendHours, $hourlyRate): void {
                $hours = max(((int) $overtimeRequest->minutes) / 60, 0);

                if ($overtimeRequest->overtime_type === OvertimeType::Holiday) {
                    $holidayHours += $hours;
                    $pay += $hours * $hourlyRate * self::OT_MULTIPLIER_HOLIDAY;

                    return;
                }

                if ($overtimeRequest->overtime_type === OvertimeType::Weekend) {
                    $weekendHours += $hours;
                    $pay += $hours * $hourlyRate * self::OT_MULTIPLIER_WEEKEND;

                    return;
                }

                $normalHours += $hours;
                $pay += $hours * $hourlyRate * self::OT_MULTIPLIER_NORMAL;
            });

        return [
            'normal_hours' => $this->roundHours($normalHours),
            'weekend_hours' => $this->roundHours($weekendHours),
            'holiday_hours' => $this->roundHours($holidayHours),
            'pay' => $this->roundMoney($pay),
        ];
    }

    /**
     * @param  array<string, true>  $holidayDates
     */
    private function unpaidLeaveUnits(
        Employee $employee,
        ?CarbonInterface $windowStart,
        ?CarbonInterface $windowEnd,
        array $holidayDates,
    ): float {
        if (! $windowStart instanceof CarbonInterface || ! $windowEnd instanceof CarbonInterface) {
            return 0.0;
        }

        $units = 0.0;

        LeaveRequest::query()
            ->where('employee_id', $employee->id)
            ->where('type', LeaveTypeCode::Unpaid->value)
            ->where('status', LeaveRequestStatus::HrApproved)
            ->whereDate('start_date', '<=', $windowEnd->toDateString())
            ->whereDate('end_date', '>=', $windowStart->toDateString())
            ->orderBy('start_date')
            ->each(function (LeaveRequest $leaveRequest) use (&$units, $holidayDates, $windowEnd, $windowStart): void {
                $leaveStart = $leaveRequest->start_date?->copy()->startOfDay();
                $leaveEnd = $leaveRequest->end_date?->copy()->startOfDay();

                if (! $leaveStart instanceof Carbon || ! $leaveEnd instanceof Carbon) {
                    return;
                }

                $overlapStart = $leaveStart->greaterThan($windowStart) ? $leaveStart : Carbon::instance($windowStart);
                $overlapEnd = $leaveEnd->lessThan($windowEnd) ? $leaveEnd : Carbon::instance($windowEnd);

                if ($overlapStart->gt($overlapEnd)) {
                    return;
                }

                if ($leaveRequest->duration_type === LeaveRequestDurationType::HalfDay) {
                    $dateKey = $overlapStart->toDateString();

                    if ($this->isWorkingDate($overlapStart, $holidayDates)) {
                        $units += 0.5;
                    }

                    return;
                }

                $units += $this->workingDateStringsBetween($overlapStart, $overlapEnd, $holidayDates)->count();
            });

        return $units;
    }

    /**
     * @param  array<string, true>  $holidayDates
     */
    private function isWorkingDate(CarbonInterface $date, array $holidayDates): bool
    {
        return ! $date->isWeekend() && ! array_key_exists($date->toDateString(), $holidayDates);
    }

    private function qualifiedTaxDependentsCount(Employee $employee, CarbonInterface $referenceDate): int
    {
        $dependents = $employee->relationLoaded('dependents')
            ? $employee->dependents
            : ($employee->relationLoaded('employeeDependents')
                ? $employee->employeeDependents
                : $employee->dependents()
                    ->active()
                    ->claimedForTax()
                    ->get());

        $qualifiedSpouseCount = $dependents
            ->filter(fn ($dependent): bool => $dependent->relationship === 'spouse')
            ->filter(fn ($dependent): bool => $dependent->is_active)
            ->filter(fn ($dependent): bool => $dependent->is_claimed_for_tax)
            ->filter(fn ($dependent): bool => $dependent->is_working === false)
            ->isNotEmpty() ? 1 : 0;

        $qualifiedChildrenCount = $dependents
            ->filter(fn ($dependent): bool => $dependent->relationship === 'child')
            ->filter(fn ($dependent): bool => $dependent->is_active)
            ->filter(fn ($dependent): bool => $dependent->is_claimed_for_tax)
            ->filter(function ($dependent) use ($referenceDate): bool {
                if ($dependent->date_of_birth === null) {
                    return false;
                }

                $age = $dependent->date_of_birth->diffInYears($referenceDate);

                return $age < 14 || ($age < 25 && $dependent->is_student);
            })
            ->count();

        return $qualifiedSpouseCount + $qualifiedChildrenCount;
    }

    private function dependentAllowance(): float
    {
        return $this->roundMoney((float) config('payroll.tax.dependent_allowance', 150.00));
    }

    private function roundMoney(float $value): float
    {
        return round($value, 2);
    }

    private function roundRate(float $value): float
    {
        return round($value, 4);
    }

    private function roundHours(float $value): float
    {
        return round($value, 2);
    }
}
