<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table): void {
            $table->unsignedInteger('overtime_minutes')->default(0)->after('early_leave_minutes');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE attendances DROP CONSTRAINT IF EXISTS attendances_minutes_check');
            DB::statement(
                'ALTER TABLE attendances ADD CONSTRAINT attendances_minutes_check CHECK (worked_minutes >= 0 AND late_minutes >= 0 AND early_leave_minutes >= 0 AND overtime_minutes >= 0)'
            );
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE attendances DROP CONSTRAINT IF EXISTS attendances_minutes_check');
            DB::statement(
                'ALTER TABLE attendances ADD CONSTRAINT attendances_minutes_check CHECK (worked_minutes >= 0 AND late_minutes >= 0 AND early_leave_minutes >= 0)'
            );
        }

        Schema::table('attendances', function (Blueprint $table): void {
            $table->dropColumn('overtime_minutes');
        });
    }
};
