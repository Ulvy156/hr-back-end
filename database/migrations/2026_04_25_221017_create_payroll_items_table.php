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
        Schema::create('payroll_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('employee_salary_id')->nullable()->constrained()->nullOnDelete();
            $table->string('salary_source', 50);
            $table->string('employee_code_snapshot', 100)->nullable();
            $table->string('employee_name_snapshot', 200);
            $table->decimal('base_salary', 12, 2);
            $table->decimal('prorated_base_salary', 12, 2);
            $table->decimal('hourly_rate', 12, 4);
            $table->decimal('daily_rate', 12, 4);
            $table->unsignedSmallInteger('eligible_working_days');
            $table->unsignedSmallInteger('company_working_days');
            $table->unsignedInteger('monthly_working_hours');
            $table->decimal('overtime_normal_hours', 8, 2)->default(0);
            $table->decimal('overtime_weekend_hours', 8, 2)->default(0);
            $table->decimal('overtime_holiday_hours', 8, 2)->default(0);
            $table->decimal('overtime_pay', 12, 2)->default(0);
            $table->decimal('unpaid_leave_units', 5, 2)->default(0);
            $table->decimal('unpaid_leave_deduction', 12, 2)->default(0);
            $table->decimal('raw_net_salary', 12, 2)->default(0);
            $table->decimal('net_salary', 12, 2)->default(0);
            $table->timestamps();

            $table->unique(['payroll_run_id', 'employee_id']);
            $table->index(['employee_id', 'created_at']);
            $table->index(['payroll_run_id', 'salary_source']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE payroll_items ADD CONSTRAINT payroll_items_salary_source_check CHECK (salary_source IN ('employee_salaries', 'employee_positions_fallback'))"
            );
            DB::statement(
                'ALTER TABLE payroll_items ADD CONSTRAINT payroll_items_amounts_check CHECK (base_salary >= 0 AND prorated_base_salary >= 0 AND hourly_rate >= 0 AND daily_rate >= 0 AND hourly_rate <= base_salary AND daily_rate <= base_salary AND eligible_working_days >= 0 AND company_working_days > 0 AND monthly_working_hours > 0 AND overtime_normal_hours >= 0 AND overtime_weekend_hours >= 0 AND overtime_holiday_hours >= 0 AND overtime_pay >= 0 AND unpaid_leave_units >= 0 AND unpaid_leave_deduction >= 0 AND raw_net_salary >= 0 AND net_salary >= 0 AND eligible_working_days <= company_working_days)'
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payroll_items');
    }
};
