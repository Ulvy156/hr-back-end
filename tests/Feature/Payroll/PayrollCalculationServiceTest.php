<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeDependent;
use App\Models\EmployeeSalary;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\PayrollTaxRule;
use App\Models\Position;
use App\Models\PublicHoliday;
use App\Services\Leave\LeaveRequestDurationType;
use App\Services\Leave\LeaveRequestHalfDaySession;
use App\Services\Leave\LeaveRequestStatus;
use App\Services\Overtime\OvertimeApprovalStage;
use App\Services\Overtime\OvertimeRequestStatus;
use App\Services\Overtime\OvertimeType;
use App\Services\Payroll\PayrollCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('payroll.tax.dependent_allowance', 150000.0);
    createPayrollTaxRule();
});

it('calculates proration for an employee who joins mid-month', function () {
    $employee = createPayrollCalculationEmployee([
        'hire_date' => '2026-04-16',
    ]);
    EmployeeSalary::query()->create([
        'employee_id' => $employee->id,
        'amount' => '2100.00',
        'effective_date' => '2026-04-01',
        'end_date' => null,
    ]);

    PublicHoliday::factory()->create([
        'holiday_date' => '2026-04-15',
        'year' => 2026,
        'country_code' => 'KH',
    ]);

    $result = app(PayrollCalculationService::class)->calculateForEmployee($employee, '2026-04');

    expect($result['company_working_days'])->toBe(21)
        ->and($result['eligible_working_days'])->toBe(11)
        ->and($result['base_salary'])->toBe(2100.0)
        ->and($result['prorated_base_salary'])->toBe(1100.0)
        ->and($result['hourly_rate'])->toBe(12.5)
        ->and($result['daily_rate'])->toBe(100.0)
        ->and($result['tax_amount'])->toBe(0.0)
        ->and($result['net_salary'])->toBe(1100.0);
});

it('calculates proration for an employee who resigns mid-month', function () {
    $employee = createPayrollCalculationEmployee([
        'last_working_date' => '2026-04-10',
    ]);
    EmployeeSalary::query()->create([
        'employee_id' => $employee->id,
        'amount' => '2100.00',
        'effective_date' => '2026-04-01',
        'end_date' => null,
    ]);

    PublicHoliday::factory()->create([
        'holiday_date' => '2026-04-15',
        'year' => 2026,
        'country_code' => 'KH',
    ]);

    $result = app(PayrollCalculationService::class)->calculateForEmployee($employee, '2026-04');

    expect($result['company_working_days'])->toBe(21)
        ->and($result['eligible_working_days'])->toBe(8)
        ->and($result['prorated_base_salary'])->toBe(800.0)
        ->and($result['tax_amount'])->toBe(0.0)
        ->and($result['net_salary'])->toBe(800.0);
});

it('calculates overtime using normal weekend and public holiday multipliers', function () {
    $employee = createPayrollCalculationEmployee();
    EmployeeSalary::query()->create([
        'employee_id' => $employee->id,
        'amount' => '1680.00',
        'effective_date' => '2026-04-01',
        'end_date' => null,
    ]);

    PublicHoliday::factory()->create([
        'holiday_date' => '2026-04-15',
        'year' => 2026,
        'country_code' => 'KH',
    ]);

    OvertimeRequest::query()->create([
        'employee_id' => $employee->id,
        'overtime_date' => '2026-04-14',
        'start_time' => '18:00:00',
        'end_time' => '20:00:00',
        'reason' => 'Weekday overtime.',
        'status' => OvertimeRequestStatus::Approved,
        'approval_stage' => OvertimeApprovalStage::Completed,
        'minutes' => 120,
        'overtime_type' => OvertimeType::Normal,
    ]);
    OvertimeRequest::query()->create([
        'employee_id' => $employee->id,
        'overtime_date' => '2026-04-18',
        'start_time' => '09:00:00',
        'end_time' => '10:00:00',
        'reason' => 'Weekend overtime.',
        'status' => OvertimeRequestStatus::Approved,
        'approval_stage' => OvertimeApprovalStage::Completed,
        'minutes' => 60,
        'overtime_type' => OvertimeType::Weekend,
    ]);
    OvertimeRequest::query()->create([
        'employee_id' => $employee->id,
        'overtime_date' => '2026-04-15',
        'start_time' => '18:00:00',
        'end_time' => '18:30:00',
        'reason' => 'Holiday overtime.',
        'status' => OvertimeRequestStatus::Approved,
        'approval_stage' => OvertimeApprovalStage::Completed,
        'minutes' => 30,
        'overtime_type' => OvertimeType::Holiday,
    ]);

    $result = app(PayrollCalculationService::class)->calculateForEmployee($employee, '2026-04');

    expect($result['company_working_days'])->toBe(21)
        ->and($result['monthly_working_hours'])->toBe(168)
        ->and($result['hourly_rate'])->toBe(10.0)
        ->and($result['overtime_normal_hours'])->toBe(2.0)
        ->and($result['overtime_weekend_hours'])->toBe(1.0)
        ->and($result['overtime_holiday_hours'])->toBe(0.5)
        ->and($result['overtime_pay'])->toBe(60.0)
        ->and($result['tax_amount'])->toBe(0.0)
        ->and($result['net_salary'])->toBe(1740.0);
});

it('ignores pending rejected and cancelled overtime requests during payroll calculation', function () {
    $employee = createPayrollCalculationEmployee();
    EmployeeSalary::query()->create([
        'employee_id' => $employee->id,
        'amount' => '1680.00',
        'effective_date' => '2026-04-01',
        'end_date' => null,
    ]);

    OvertimeRequest::query()->create([
        'employee_id' => $employee->id,
        'overtime_date' => '2026-04-14',
        'start_time' => '18:00:00',
        'end_time' => '20:00:00',
        'reason' => 'Approved overtime.',
        'status' => OvertimeRequestStatus::Approved,
        'approval_stage' => OvertimeApprovalStage::Completed,
        'minutes' => 120,
        'overtime_type' => OvertimeType::Normal,
    ]);
    OvertimeRequest::query()->create([
        'employee_id' => $employee->id,
        'overtime_date' => '2026-04-15',
        'start_time' => '18:00:00',
        'end_time' => '20:00:00',
        'reason' => 'Pending overtime.',
        'status' => OvertimeRequestStatus::Pending,
        'approval_stage' => OvertimeApprovalStage::ManagerReview,
        'minutes' => 120,
        'overtime_type' => OvertimeType::Holiday,
    ]);
    OvertimeRequest::query()->create([
        'employee_id' => $employee->id,
        'overtime_date' => '2026-04-16',
        'start_time' => '18:00:00',
        'end_time' => '20:00:00',
        'reason' => 'Rejected overtime.',
        'status' => OvertimeRequestStatus::Rejected,
        'approval_stage' => OvertimeApprovalStage::Completed,
        'minutes' => 120,
        'overtime_type' => OvertimeType::Normal,
    ]);
    OvertimeRequest::query()->create([
        'employee_id' => $employee->id,
        'overtime_date' => '2026-04-19',
        'start_time' => '09:00:00',
        'end_time' => '11:00:00',
        'reason' => 'Cancelled overtime.',
        'status' => OvertimeRequestStatus::Cancelled,
        'approval_stage' => OvertimeApprovalStage::Completed,
        'minutes' => 120,
        'overtime_type' => OvertimeType::Weekend,
    ]);

    $result = app(PayrollCalculationService::class)->calculateForEmployee($employee, '2026-04');

    expect($result['overtime_normal_hours'])->toBe(2.0)
        ->and($result['overtime_weekend_hours'])->toBe(0.0)
        ->and($result['overtime_holiday_hours'])->toBe(0.0)
        ->and($result['overtime_pay'])->toBe(28.64)
        ->and($result['net_salary'])->toBe(1708.64);
});

it('recomputes approved unpaid leave units using payroll working-day calendar', function () {
    $employee = createPayrollCalculationEmployee();
    EmployeeSalary::query()->create([
        'employee_id' => $employee->id,
        'amount' => '2100.00',
        'effective_date' => '2026-04-01',
        'end_date' => null,
    ]);

    PublicHoliday::factory()->create([
        'holiday_date' => '2026-04-15',
        'year' => 2026,
        'country_code' => 'KH',
    ]);

    LeaveRequest::query()->create([
        'employee_id' => $employee->id,
        'type' => 'unpaid',
        'reason' => 'Extended personal leave.',
        'duration_type' => LeaveRequestDurationType::FullDay,
        'half_day_session' => null,
        'start_date' => '2026-04-14',
        'end_date' => '2026-04-18',
        'status' => LeaveRequestStatus::HrApproved,
    ]);
    LeaveRequest::query()->create([
        'employee_id' => $employee->id,
        'type' => 'unpaid',
        'reason' => 'Personal errand.',
        'duration_type' => LeaveRequestDurationType::HalfDay,
        'half_day_session' => LeaveRequestHalfDaySession::Pm,
        'start_date' => '2026-04-20',
        'end_date' => '2026-04-20',
        'status' => LeaveRequestStatus::HrApproved,
    ]);
    LeaveRequest::query()->create([
        'employee_id' => $employee->id,
        'type' => 'unpaid',
        'reason' => 'Pending unpaid leave.',
        'duration_type' => LeaveRequestDurationType::FullDay,
        'half_day_session' => null,
        'start_date' => '2026-04-21',
        'end_date' => '2026-04-21',
        'status' => LeaveRequestStatus::Pending,
    ]);
    LeaveRequest::query()->create([
        'employee_id' => $employee->id,
        'type' => 'annual',
        'reason' => 'Annual leave should not deduct payroll here.',
        'duration_type' => LeaveRequestDurationType::FullDay,
        'half_day_session' => null,
        'start_date' => '2026-04-22',
        'end_date' => '2026-04-22',
        'status' => LeaveRequestStatus::HrApproved,
    ]);

    $result = app(PayrollCalculationService::class)->calculateForEmployee($employee, '2026-04');

    expect($result['daily_rate'])->toBe(100.0)
        ->and($result['unpaid_leave_units'])->toBe(3.5)
        ->and($result['unpaid_leave_deduction'])->toBe(350.0)
        ->and($result['tax_amount'])->toBe(0.0)
        ->and($result['net_salary'])->toBe(1750.0);
});

it('deducts one full day for approved unpaid full-day leave', function () {
    $employee = createPayrollCalculationEmployee();
    EmployeeSalary::query()->create([
        'employee_id' => $employee->id,
        'amount' => '2100.00',
        'effective_date' => '2026-04-01',
        'end_date' => null,
    ]);

    LeaveRequest::query()->create([
        'employee_id' => $employee->id,
        'type' => 'unpaid',
        'reason' => 'Full-day unpaid leave.',
        'duration_type' => LeaveRequestDurationType::FullDay,
        'half_day_session' => null,
        'start_date' => '2026-04-14',
        'end_date' => '2026-04-14',
        'status' => LeaveRequestStatus::HrApproved,
    ]);

    $result = app(PayrollCalculationService::class)->calculateForEmployee($employee, '2026-04');

    expect($result['company_working_days'])->toBe(22)
        ->and($result['daily_rate'])->toBe(95.4545)
        ->and($result['unpaid_leave_units'])->toBe(1.0)
        ->and($result['unpaid_leave_deduction'])->toBe(95.45)
        ->and($result['tax_amount'])->toBe(0.0)
        ->and($result['net_salary'])->toBe(2004.55);
});

it('deducts half a day for approved unpaid half-day leave', function () {
    $employee = createPayrollCalculationEmployee();
    EmployeeSalary::query()->create([
        'employee_id' => $employee->id,
        'amount' => '2100.00',
        'effective_date' => '2026-04-01',
        'end_date' => null,
    ]);

    LeaveRequest::query()->create([
        'employee_id' => $employee->id,
        'type' => 'unpaid',
        'reason' => 'Half-day unpaid leave.',
        'duration_type' => LeaveRequestDurationType::HalfDay,
        'half_day_session' => LeaveRequestHalfDaySession::Am,
        'start_date' => '2026-04-14',
        'end_date' => '2026-04-14',
        'status' => LeaveRequestStatus::HrApproved,
    ]);

    $result = app(PayrollCalculationService::class)->calculateForEmployee($employee, '2026-04');

    expect($result['company_working_days'])->toBe(22)
        ->and($result['daily_rate'])->toBe(95.4545)
        ->and($result['unpaid_leave_units'])->toBe(0.5)
        ->and($result['unpaid_leave_deduction'])->toBe(47.73)
        ->and($result['tax_amount'])->toBe(0.0)
        ->and($result['net_salary'])->toBe(2052.27);
});

it('never returns a negative net salary', function () {
    $employee = createPayrollCalculationEmployee();
    EmployeeSalary::query()->create([
        'employee_id' => $employee->id,
        'amount' => '2100.00',
        'effective_date' => '2026-04-01',
        'end_date' => null,
    ]);

    PublicHoliday::factory()->create([
        'holiday_date' => '2026-04-15',
        'year' => 2026,
        'country_code' => 'KH',
    ]);

    LeaveRequest::query()->create([
        'employee_id' => $employee->id,
        'type' => 'unpaid',
        'reason' => 'Full month unpaid leave.',
        'duration_type' => LeaveRequestDurationType::FullDay,
        'half_day_session' => null,
        'start_date' => '2026-04-01',
        'end_date' => '2026-04-30',
        'status' => LeaveRequestStatus::HrApproved,
    ]);
    LeaveRequest::query()->create([
        'employee_id' => $employee->id,
        'type' => 'unpaid',
        'reason' => 'Overlapping extra half-day for defensive cap test.',
        'duration_type' => LeaveRequestDurationType::HalfDay,
        'half_day_session' => LeaveRequestHalfDaySession::Am,
        'start_date' => '2026-04-30',
        'end_date' => '2026-04-30',
        'status' => LeaveRequestStatus::HrApproved,
    ]);

    $result = app(PayrollCalculationService::class)->calculateForEmployee($employee, '2026-04');

    expect($result['unpaid_leave_deduction'])->toBe(2150.0)
        ->and($result['tax_amount'])->toBe(0.0)
        ->and($result['gross_net_salary'])->toBe(-50.0)
        ->and($result['net_salary'])->toBe(0.0);
});

it('selects the matching active tax rule and calculates percentage-based tax', function () {
    PayrollTaxRule::query()->delete();

    createPayrollTaxRule([
        'name' => 'Lower Band',
        'rate_percentage' => '5.00',
        'min_salary' => '0.00',
        'max_salary' => '1000.00',
    ]);
    createPayrollTaxRule([
        'name' => 'Middle Band',
        'rate_percentage' => '10.00',
        'min_salary' => '1000.01',
        'max_salary' => '2000.00',
    ]);
    createPayrollTaxRule([
        'name' => 'Upper Band',
        'rate_percentage' => '15.00',
        'min_salary' => '2000.01',
        'max_salary' => null,
    ]);

    $employee = createPayrollCalculationEmployee();
    EmployeeSalary::query()->create([
        'employee_id' => $employee->id,
        'amount' => '1500.00',
        'effective_date' => '2026-04-01',
        'end_date' => null,
    ]);

    $result = app(PayrollCalculationService::class)->calculateForEmployee($employee, '2026-04');

    expect($result['prorated_base_salary'])->toBe(1500.0)
        ->and($result['tax_amount'])->toBe(150.0)
        ->and($result['gross_net_salary'])->toBe(1350.0)
        ->and($result['net_salary'])->toBe(1350.0);
});

it('calculates tax correctly at salary bracket boundaries', function (string $salary, float $expectedTax) {
    PayrollTaxRule::query()->delete();

    createPayrollTaxRule([
        'name' => 'Lower Band',
        'rate_percentage' => '5.00',
        'min_salary' => '0.00',
        'max_salary' => '1000.00',
    ]);
    createPayrollTaxRule([
        'name' => 'Middle Band',
        'rate_percentage' => '10.00',
        'min_salary' => '1000.01',
        'max_salary' => '2000.00',
    ]);
    createPayrollTaxRule([
        'name' => 'Upper Band',
        'rate_percentage' => '15.00',
        'min_salary' => '2000.01',
        'max_salary' => null,
    ]);

    $employee = createPayrollCalculationEmployee();
    EmployeeSalary::query()->create([
        'employee_id' => $employee->id,
        'amount' => $salary,
        'effective_date' => '2026-04-01',
        'end_date' => null,
    ]);

    $result = app(PayrollCalculationService::class)->calculateForEmployee($employee, '2026-04');

    expect($result['prorated_base_salary'])->toBe((float) $salary)
        ->and($result['tax_amount'])->toBe($expectedTax)
        ->and($result['net_salary'])->toBe(round((float) $salary - $expectedTax, 2));
})->with([
    ['1000.00', 50.0],
    ['1000.01', 100.0],
    ['2000.00', 200.0],
    ['2000.01', 300.0],
]);

it('rejects overlapping tax brackets that match the same salary', function () {
    PayrollTaxRule::query()->delete();

    createPayrollTaxRule([
        'name' => 'Band A',
        'rate_percentage' => '5.00',
        'min_salary' => '0.00',
        'max_salary' => '1500.00',
    ]);
    createPayrollTaxRule([
        'name' => 'Band B',
        'rate_percentage' => '7.50',
        'min_salary' => '1000.00',
        'max_salary' => '2000.00',
    ]);

    $employee = createPayrollCalculationEmployee();
    EmployeeSalary::query()->create([
        'employee_id' => $employee->id,
        'amount' => '1200.00',
        'effective_date' => '2026-04-01',
        'end_date' => null,
    ]);

    try {
        app(PayrollCalculationService::class)->calculateForEmployee($employee, '2026-04');

        $this->fail('Expected overlapping payroll tax rules to trigger a validation exception.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('tax_rule')
            ->and($exception->errors()['tax_rule'][0])->toBe('Multiple active payroll tax rules match the calculated salary for the selected month.');
    }
});

it('counts only one qualified non-working spouse for tax reduction', function () {
    PayrollTaxRule::query()->delete();

    createPayrollTaxRule([
        'name' => 'Lower Band',
        'rate_percentage' => '0.00',
        'min_salary' => '0.00',
        'max_salary' => '400000.00',
    ]);
    createPayrollTaxRule([
        'name' => 'Middle Band',
        'rate_percentage' => '10.00',
        'min_salary' => '400000.01',
        'max_salary' => null,
    ]);

    $employee = createPayrollCalculationEmployee();
    EmployeeSalary::query()->create([
        'employee_id' => $employee->id,
        'amount' => '500000.00',
        'effective_date' => '2026-04-01',
        'end_date' => null,
    ]);
    EmployeeDependent::factory()->create([
        'employee_id' => $employee->id,
        'relationship' => 'spouse',
        'date_of_birth' => '1990-01-01',
        'is_active' => true,
        'is_working' => false,
        'is_student' => false,
        'is_claimed_for_tax' => true,
    ]);
    EmployeeDependent::factory()->create([
        'employee_id' => $employee->id,
        'relationship' => 'spouse',
        'date_of_birth' => '1992-01-01',
        'is_active' => true,
        'is_working' => true,
        'is_student' => false,
        'is_claimed_for_tax' => true,
    ]);
    EmployeeDependent::factory()->create([
        'employee_id' => $employee->id,
        'relationship' => 'spouse',
        'date_of_birth' => '1994-01-01',
        'is_active' => true,
        'is_working' => false,
        'is_student' => false,
        'is_claimed_for_tax' => true,
    ]);

    $result = app(PayrollCalculationService::class)->calculateForEmployee($employee, '2026-04');

    expect($result['dependents_count'])->toBe(1)
        ->and($result['dependent_allowance'])->toBe(150000.0)
        ->and($result['taxable_salary'])->toBe(350000.0)
        ->and($result['tax_amount'])->toBe(0.0)
        ->and($result['net_salary'])->toBe(500000.0);
});

it('counts a claimed child under 14 as a tax dependent', function () {
    PayrollTaxRule::query()->delete();

    createPayrollTaxRule([
        'name' => 'Lower Band',
        'rate_percentage' => '0.00',
        'min_salary' => '0.00',
        'max_salary' => '400000.00',
    ]);
    createPayrollTaxRule([
        'name' => 'Middle Band',
        'rate_percentage' => '10.00',
        'min_salary' => '400000.01',
        'max_salary' => null,
    ]);

    $employee = createPayrollCalculationEmployee();
    EmployeeSalary::query()->create([
        'employee_id' => $employee->id,
        'amount' => '500000.00',
        'effective_date' => '2026-04-01',
        'end_date' => null,
    ]);
    EmployeeDependent::factory()->create([
        'employee_id' => $employee->id,
        'relationship' => 'child',
        'date_of_birth' => '2014-05-01',
        'is_active' => true,
        'is_working' => false,
        'is_student' => false,
        'is_claimed_for_tax' => true,
    ]);

    $result = app(PayrollCalculationService::class)->calculateForEmployee($employee, '2026-04');

    expect($result['dependents_count'])->toBe(1)
        ->and($result['taxable_salary'])->toBe(350000.0)
        ->and($result['tax_amount'])->toBe(0.0)
        ->and($result['net_salary'])->toBe(500000.0);
});

it('counts a claimed student child under 25 as a tax dependent', function () {
    PayrollTaxRule::query()->delete();

    createPayrollTaxRule([
        'name' => 'Lower Band',
        'rate_percentage' => '0.00',
        'min_salary' => '0.00',
        'max_salary' => '400000.00',
    ]);
    createPayrollTaxRule([
        'name' => 'Middle Band',
        'rate_percentage' => '10.00',
        'min_salary' => '400000.01',
        'max_salary' => null,
    ]);

    $employee = createPayrollCalculationEmployee();
    EmployeeSalary::query()->create([
        'employee_id' => $employee->id,
        'amount' => '500000.00',
        'effective_date' => '2026-04-01',
        'end_date' => null,
    ]);
    EmployeeDependent::factory()->create([
        'employee_id' => $employee->id,
        'relationship' => 'child',
        'date_of_birth' => '2004-05-01',
        'is_active' => true,
        'is_working' => false,
        'is_student' => true,
        'is_claimed_for_tax' => true,
    ]);

    $result = app(PayrollCalculationService::class)->calculateForEmployee($employee, '2026-04');

    expect($result['dependents_count'])->toBe(1)
        ->and($result['taxable_salary'])->toBe(350000.0)
        ->and($result['tax_amount'])->toBe(0.0)
        ->and($result['net_salary'])->toBe(500000.0);
});

it('ignores children who are over the Cambodia tax age limit', function () {
    PayrollTaxRule::query()->delete();

    createPayrollTaxRule([
        'name' => 'Lower Band',
        'rate_percentage' => '0.00',
        'min_salary' => '0.00',
        'max_salary' => '400000.00',
    ]);
    createPayrollTaxRule([
        'name' => 'Middle Band',
        'rate_percentage' => '10.00',
        'min_salary' => '400000.01',
        'max_salary' => null,
    ]);

    $employee = createPayrollCalculationEmployee();
    EmployeeSalary::query()->create([
        'employee_id' => $employee->id,
        'amount' => '500000.00',
        'effective_date' => '2026-04-01',
        'end_date' => null,
    ]);
    EmployeeDependent::factory()->create([
        'employee_id' => $employee->id,
        'relationship' => 'child',
        'date_of_birth' => '2000-04-01',
        'is_active' => true,
        'is_working' => false,
        'is_student' => true,
        'is_claimed_for_tax' => true,
    ]);

    $result = app(PayrollCalculationService::class)->calculateForEmployee($employee, '2026-04');

    expect($result['dependents_count'])->toBe(0)
        ->and($result['taxable_salary'])->toBe(500000.0)
        ->and($result['tax_amount'])->toBe(50000.0)
        ->and($result['net_salary'])->toBe(450000.0);
});

it('counts multiple valid children and ignores inactive or unclaimed children', function () {
    PayrollTaxRule::query()->delete();

    createPayrollTaxRule([
        'name' => 'Lower Band',
        'rate_percentage' => '0.00',
        'min_salary' => '0.00',
        'max_salary' => '400000.00',
    ]);
    createPayrollTaxRule([
        'name' => 'Middle Band',
        'rate_percentage' => '10.00',
        'min_salary' => '400000.01',
        'max_salary' => null,
    ]);

    $employee = createPayrollCalculationEmployee();
    EmployeeSalary::query()->create([
        'employee_id' => $employee->id,
        'amount' => '800000.00',
        'effective_date' => '2026-04-01',
        'end_date' => null,
    ]);
    EmployeeDependent::factory()->count(3)->create([
        'employee_id' => $employee->id,
        'relationship' => 'child',
        'date_of_birth' => '2015-01-01',
        'is_active' => true,
        'is_working' => false,
        'is_student' => false,
        'is_claimed_for_tax' => true,
    ]);
    EmployeeDependent::factory()->create([
        'employee_id' => $employee->id,
        'relationship' => 'child',
        'date_of_birth' => '2016-01-01',
        'is_active' => false,
        'is_working' => false,
        'is_student' => false,
        'is_claimed_for_tax' => true,
    ]);
    EmployeeDependent::factory()->create([
        'employee_id' => $employee->id,
        'relationship' => 'child',
        'date_of_birth' => '2017-01-01',
        'is_active' => true,
        'is_working' => false,
        'is_student' => false,
        'is_claimed_for_tax' => false,
    ]);

    $result = app(PayrollCalculationService::class)->calculateForEmployee($employee, '2026-04');

    expect($result['dependents_count'])->toBe(3)
        ->and($result['taxable_salary'])->toBe(350000.0)
        ->and($result['tax_amount'])->toBe(0.0)
        ->and($result['net_salary'])->toBe(800000.0);
});

it('never lets taxable salary drop below zero after Cambodia dependent deductions', function () {
    PayrollTaxRule::query()->delete();

    createPayrollTaxRule([
        'name' => 'Lower Band',
        'rate_percentage' => '0.00',
        'min_salary' => '0.00',
        'max_salary' => null,
    ]);

    $employee = createPayrollCalculationEmployee();
    EmployeeSalary::query()->create([
        'employee_id' => $employee->id,
        'amount' => '200000.00',
        'effective_date' => '2026-04-01',
        'end_date' => null,
    ]);
    EmployeeDependent::factory()->create([
        'employee_id' => $employee->id,
        'relationship' => 'spouse',
        'date_of_birth' => '1990-01-01',
        'is_active' => true,
        'is_working' => false,
        'is_student' => false,
        'is_claimed_for_tax' => true,
    ]);
    EmployeeDependent::factory()->create([
        'employee_id' => $employee->id,
        'relationship' => 'child',
        'date_of_birth' => '2018-01-01',
        'is_active' => true,
        'is_working' => false,
        'is_student' => false,
        'is_claimed_for_tax' => true,
    ]);

    $result = app(PayrollCalculationService::class)->calculateForEmployee($employee, '2026-04');

    expect($result['dependents_count'])->toBe(2)
        ->and($result['taxable_salary'])->toBe(0.0)
        ->and($result['tax_amount'])->toBe(0.0)
        ->and($result['net_salary'])->toBe(200000.0);
});

/**
 * @param  array{hire_date?: string, termination_date?: string|null, last_working_date?: string|null}  $overrides
 */
function createPayrollCalculationEmployee(array $overrides = []): Employee
{
    $department = Department::query()->create([
        'name' => 'Payroll Calc '.str()->random(6),
    ]);
    $position = Position::query()->create([
        'title' => 'Payroll Analyst '.str()->random(6),
    ]);

    $employee = Employee::query()->create([
        'department_id' => $department->id,
        'current_position_id' => $position->id,
        'employee_code' => 'EMP'.fake()->unique()->numerify('####'),
        'first_name' => 'Calc',
        'last_name' => 'Employee',
        'email' => fake()->unique()->safeEmail(),
        'phone' => '012345678',
        'hire_date' => $overrides['hire_date'] ?? '2026-01-01',
        'termination_date' => $overrides['termination_date'] ?? null,
        'last_working_date' => $overrides['last_working_date'] ?? null,
        'status' => 'active',
    ]);

    $employee->employeePositions()->create([
        'position_id' => $position->id,
        'base_salary' => 500,
        'start_date' => '2026-01-01',
        'end_date' => null,
    ]);

    return $employee->fresh();
}

/**
 * @param  array{name?: string, rate_percentage?: string, min_salary?: string, max_salary?: string|null, is_active?: bool, effective_from?: string, effective_to?: string|null}  $overrides
 */
function createPayrollTaxRule(array $overrides = []): PayrollTaxRule
{
    return PayrollTaxRule::query()->create([
        'name' => $overrides['name'] ?? 'Default Payroll Tax Rule',
        'rate_percentage' => $overrides['rate_percentage'] ?? '0.00',
        'min_salary' => $overrides['min_salary'] ?? '0.00',
        'max_salary' => $overrides['max_salary'] ?? null,
        'is_active' => $overrides['is_active'] ?? true,
        'effective_from' => $overrides['effective_from'] ?? '2026-01-01',
        'effective_to' => $overrides['effective_to'] ?? null,
    ]);
}
