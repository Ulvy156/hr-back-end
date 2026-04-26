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
        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->date('payroll_month')->unique();
            $table->string('status', 20)->default('draft')->index();
            $table->unsignedSmallInteger('company_working_days');
            $table->unsignedInteger('monthly_working_hours');
            $table->unsignedInteger('employee_count')->default(0);
            $table->decimal('total_base_salary', 14, 2)->default(0);
            $table->decimal('total_prorated_base_salary', 14, 2)->default(0);
            $table->decimal('total_overtime_pay', 14, 2)->default(0);
            $table->decimal('total_unpaid_leave_deduction', 14, 2)->default(0);
            $table->decimal('total_net_salary', 14, 2)->default(0);
            $table->timestamps();

            $table->index(['status', 'payroll_month']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE payroll_runs ADD CONSTRAINT payroll_runs_status_check CHECK (status IN ('draft', 'approved', 'paid', 'cancelled'))"
            );
            DB::statement(
                'ALTER TABLE payroll_runs ADD CONSTRAINT payroll_runs_totals_check CHECK (company_working_days > 0 AND monthly_working_hours > 0 AND employee_count >= 0 AND total_base_salary >= 0 AND total_prorated_base_salary >= 0 AND total_overtime_pay >= 0 AND total_unpaid_leave_deduction >= 0 AND total_net_salary >= 0)'
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payroll_runs');
    }
};
