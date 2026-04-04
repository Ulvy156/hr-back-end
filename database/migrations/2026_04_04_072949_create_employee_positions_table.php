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
        Schema::create('employee_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('position_id')->index()->constrained()->restrictOnDelete();
            $table->decimal('base_salary', 12, 2);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'start_date']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE employee_positions ADD CONSTRAINT employee_positions_date_check CHECK (end_date IS NULL OR end_date >= start_date)"
            );
            DB::statement(
                'ALTER TABLE employee_positions ADD CONSTRAINT employee_positions_base_salary_check CHECK (base_salary >= 0)'
            );
        }

        if (in_array(Schema::getConnection()->getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement(
                'CREATE UNIQUE INDEX employee_positions_active_employee_unique ON employee_positions (employee_id) WHERE end_date IS NULL'
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (in_array(Schema::getConnection()->getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS employee_positions_active_employee_unique');
        }

        Schema::dropIfExists('employee_positions');
    }
};
