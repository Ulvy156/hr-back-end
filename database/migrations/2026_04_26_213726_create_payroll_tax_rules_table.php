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
        Schema::create('payroll_tax_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->decimal('rate_percentage', 5, 2);
            $table->decimal('min_salary', 12, 2);
            $table->decimal('max_salary', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'effective_from', 'effective_to'], 'payroll_tax_rules_active_dates_idx');
            $table->index(['min_salary', 'max_salary'], 'payroll_tax_rules_salary_range_idx');
            $table->index(['name', 'effective_from'], 'payroll_tax_rules_name_effective_idx');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE payroll_tax_rules ADD CONSTRAINT payroll_tax_rules_amounts_check CHECK (rate_percentage >= 0 AND rate_percentage <= 100 AND min_salary >= 0 AND (max_salary IS NULL OR max_salary >= min_salary))'
            );
            DB::statement(
                'ALTER TABLE payroll_tax_rules ADD CONSTRAINT payroll_tax_rules_dates_check CHECK (effective_to IS NULL OR effective_to >= effective_from)'
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payroll_tax_rules');
    }
};
