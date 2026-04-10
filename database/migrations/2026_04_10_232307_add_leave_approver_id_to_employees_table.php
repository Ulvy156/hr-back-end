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
        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('leave_approver_id')
                ->nullable()
                ->after('manager_id')
                ->index()
                ->constrained('employees')
                ->nullOnDelete();
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE employees ADD CONSTRAINT employees_leave_approver_check CHECK (leave_approver_id IS NULL OR leave_approver_id <> id)'
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE employees DROP CONSTRAINT IF EXISTS employees_leave_approver_check');
        }

        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('leave_approver_id');
        });
    }
};
