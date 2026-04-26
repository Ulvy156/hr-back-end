<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->decimal('total_tax_amount', 14, 2)->default(0)->after('total_unpaid_leave_deduction');
        });

        Schema::table('payroll_items', function (Blueprint $table) {
            $table->decimal('tax_amount', 12, 2)->default(0)->after('unpaid_leave_deduction');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE payroll_runs DROP CONSTRAINT IF EXISTS payroll_runs_totals_check');
            DB::statement(
                'ALTER TABLE payroll_runs ADD CONSTRAINT payroll_runs_totals_check CHECK (company_working_days > 0 AND monthly_working_hours > 0 AND employee_count >= 0 AND total_base_salary >= 0 AND total_prorated_base_salary >= 0 AND total_overtime_pay >= 0 AND total_unpaid_leave_deduction >= 0 AND total_tax_amount >= 0 AND total_net_salary >= 0)'
            );

            DB::statement('ALTER TABLE payroll_items DROP CONSTRAINT IF EXISTS payroll_items_amounts_check');
            DB::statement(
                'ALTER TABLE payroll_items ADD CONSTRAINT payroll_items_amounts_check CHECK (base_salary >= 0 AND prorated_base_salary >= 0 AND hourly_rate >= 0 AND daily_rate >= 0 AND hourly_rate <= base_salary AND daily_rate <= base_salary AND eligible_working_days >= 0 AND company_working_days > 0 AND monthly_working_hours > 0 AND overtime_normal_hours >= 0 AND overtime_weekend_hours >= 0 AND overtime_holiday_hours >= 0 AND overtime_pay >= 0 AND unpaid_leave_units >= 0 AND unpaid_leave_deduction >= 0 AND tax_amount >= 0 AND raw_net_salary >= 0 AND net_salary >= 0 AND eligible_working_days <= company_working_days)'
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE payroll_runs DROP CONSTRAINT IF EXISTS payroll_runs_totals_check');
            DB::statement('ALTER TABLE payroll_items DROP CONSTRAINT IF EXISTS payroll_items_amounts_check');
        }

        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->dropColumn('total_tax_amount');
        });

        Schema::table('payroll_items', function (Blueprint $table) {
            $table->dropColumn('tax_amount');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE payroll_runs DROP CONSTRAINT IF EXISTS payroll_runs_totals_check');
            DB::statement(
                'ALTER TABLE payroll_runs ADD CONSTRAINT payroll_runs_totals_check CHECK (company_working_days > 0 AND monthly_working_hours > 0 AND employee_count >= 0 AND total_base_salary >= 0 AND total_prorated_base_salary >= 0 AND total_overtime_pay >= 0 AND total_unpaid_leave_deduction >= 0 AND total_net_salary >= 0)'
            );

            DB::statement(
                'ALTER TABLE payroll_items ADD CONSTRAINT payroll_items_amounts_check CHECK (base_salary >= 0 AND prorated_base_salary >= 0 AND hourly_rate >= 0 AND daily_rate >= 0 AND hourly_rate <= base_salary AND daily_rate <= base_salary AND eligible_working_days >= 0 AND company_working_days > 0 AND monthly_working_hours > 0 AND overtime_normal_hours >= 0 AND overtime_weekend_hours >= 0 AND overtime_holiday_hours >= 0 AND overtime_pay >= 0 AND unpaid_leave_units >= 0 AND unpaid_leave_deduction >= 0 AND raw_net_salary >= 0 AND net_salary >= 0 AND eligible_working_days <= company_working_days)'
            );
        }
    }
};
