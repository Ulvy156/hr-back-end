<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $timestamp = now();
        $activePositionStartDates = DB::table('employee_positions')
            ->selectRaw('employee_id, MAX(start_date) AS active_start_date')
            ->whereNull('end_date')
            ->where('base_salary', '>', 0)
            ->groupBy('employee_id');

        $activeEmployeePositions = DB::table('employee_positions')
            ->select([
                'employee_positions.employee_id',
                'employee_positions.base_salary',
                'employee_positions.start_date',
            ])
            ->joinSub(
                $activePositionStartDates,
                'active_positions',
                function ($join): void {
                    $join->on('active_positions.employee_id', '=', 'employee_positions.employee_id')
                        ->on('active_positions.active_start_date', '=', 'employee_positions.start_date');
                }
            )
            ->orderBy('employee_positions.employee_id')
            ->orderBy('employee_positions.start_date')
            ->get();

        foreach ($activeEmployeePositions as $employeePosition) {
            DB::table('employee_salaries')->updateOrInsert(
                [
                    'employee_id' => $employeePosition->employee_id,
                    'effective_date' => $employeePosition->start_date,
                ],
                [
                    'amount' => $employeePosition->base_salary,
                    'end_date' => null,
                    'updated_at' => $timestamp,
                    'created_at' => $timestamp,
                ],
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentionally left blank to avoid removing salary history that may already be in use.
    }
};
