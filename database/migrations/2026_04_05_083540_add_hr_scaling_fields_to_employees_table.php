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
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('employee_code', 30)->nullable()->after('id');
            $table->foreignId('branch_id')->nullable()->index()->after('department_id');
            $table->foreignId('shift_id')->nullable()->index()->after('current_position_id');
            $table->string('employment_type', 30)->nullable()->index()->after('hire_date');
            $table->date('confirmation_date')->nullable()->after('employment_type');
            $table->date('termination_date')->nullable()->after('confirmation_date');
            $table->date('last_working_date')->nullable()->after('termination_date');
            $table->string('profile_photo_path', 2048)->nullable()->after('emergency_contact_phone');
        });

        DB::table('employees')
            ->select('id')
            ->orderBy('id')
            ->get()
            ->each(function (object $employee): void {
                DB::table('employees')
                    ->where('id', $employee->id)
                    ->update([
                        'employee_code' => sprintf('EMP%06d', $employee->id),
                    ]);
            });

        Schema::table('employees', function (Blueprint $table): void {
            $table->unique('employee_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropUnique(['employee_code']);
            $table->dropColumn([
                'employee_code',
                'branch_id',
                'shift_id',
                'employment_type',
                'confirmation_date',
                'termination_date',
                'last_working_date',
                'profile_photo_path',
            ]);
        });
    }
};
