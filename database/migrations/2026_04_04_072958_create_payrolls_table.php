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
        Schema::create('payrolls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->index()->constrained()->restrictOnDelete();
            $table->smallInteger('month');
            $table->smallInteger('year');
            $table->decimal('base_salary', 12, 2);
            $table->decimal('total_allowance', 12, 2)->default(0);
            $table->decimal('total_deduction', 12, 2)->default(0);
            $table->decimal('net_salary', 12, 2);
            $table->timestamps();
            $table->unique(['employee_id', 'year', 'month']);
            $table->index(['year', 'month']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE payrolls ADD CONSTRAINT payrolls_month_check CHECK (month BETWEEN 1 AND 12)"
            );
            DB::statement(
                'ALTER TABLE payrolls ADD CONSTRAINT payrolls_amounts_check CHECK (base_salary >= 0 AND total_allowance >= 0 AND total_deduction >= 0 AND net_salary >= 0)'
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payrolls');
    }
};
