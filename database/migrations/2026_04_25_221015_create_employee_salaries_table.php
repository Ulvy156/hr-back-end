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
        Schema::create('employee_salaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->index()->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('effective_date');
            $table->date('end_date')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'effective_date']);
            $table->index(['employee_id', 'end_date']);
            $table->unique(['employee_id', 'effective_date']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE employee_salaries ADD CONSTRAINT employee_salaries_amount_check CHECK (amount > 0)'
            );
            DB::statement(
                'ALTER TABLE employee_salaries ADD CONSTRAINT employee_salaries_date_check CHECK (end_date IS NULL OR end_date >= effective_date)'
            );
        }

        if (in_array(Schema::getConnection()->getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement(
                'CREATE UNIQUE INDEX employee_salaries_active_employee_unique ON employee_salaries (employee_id) WHERE end_date IS NULL'
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (in_array(Schema::getConnection()->getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS employee_salaries_active_employee_unique');
        }

        Schema::dropIfExists('employee_salaries');
    }
};
