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
        DB::table('employees')
            ->select(['id', 'user_id', 'first_name', 'last_name', 'profile_photo', 'profile_photo_path'])
            ->orderBy('id')
            ->get()
            ->each(function (object $employee): void {
                if (
                    ($employee->profile_photo_path === null || $employee->profile_photo_path === '')
                    && is_string($employee->profile_photo)
                    && $employee->profile_photo !== ''
                ) {
                    DB::table('employees')
                        ->where('id', $employee->id)
                        ->update([
                            'profile_photo_path' => $employee->profile_photo,
                        ]);
                }

                if ($employee->user_id === null) {
                    return;
                }

                $fullName = trim(sprintf('%s %s', $employee->first_name, $employee->last_name));

                if ($fullName === '') {
                    return;
                }

                DB::table('users')
                    ->where('id', $employee->user_id)
                    ->update([
                        'name' => $fullName,
                    ]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // This backfill is intentionally irreversible.
    }
};
