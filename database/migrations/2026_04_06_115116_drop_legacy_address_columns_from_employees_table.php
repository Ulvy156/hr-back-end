<?php

use App\EmployeeAddressType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $hasCurrentAddressColumn = Schema::hasColumn('employees', 'current_address');
        $hasPermanentAddressColumn = Schema::hasColumn('employees', 'permanent_address');

        if (! $hasCurrentAddressColumn && ! $hasPermanentAddressColumn) {
            return;
        }

        $timestamp = now();

        DB::table('employees')
            ->select('id', 'current_address', 'permanent_address')
            ->orderBy('id')
            ->chunkById(500, function (Collection $employees) use ($timestamp): void {
                $addresses = [];

                foreach ($employees as $employee) {
                    $currentAddress = is_string($employee->current_address) ? trim($employee->current_address) : null;
                    $permanentAddress = is_string($employee->permanent_address) ? trim($employee->permanent_address) : null;

                    if ($currentAddress !== null && $currentAddress !== '') {
                        $addresses[] = [
                            'employee_id' => $employee->id,
                            'address_type' => EmployeeAddressType::Current->value,
                            'address_line' => $currentAddress,
                            'is_primary' => true,
                            'created_at' => $timestamp,
                            'updated_at' => $timestamp,
                        ];
                    }

                    if ($permanentAddress !== null && $permanentAddress !== '') {
                        $addresses[] = [
                            'employee_id' => $employee->id,
                            'address_type' => EmployeeAddressType::Permanent->value,
                            'address_line' => $permanentAddress,
                            'is_primary' => $currentAddress === null || $currentAddress === '',
                            'created_at' => $timestamp,
                            'updated_at' => $timestamp,
                        ];
                    }
                }

                if ($addresses !== []) {
                    DB::table('employee_addresses')->insert($addresses);
                }
            });

        Schema::table('employees', function (Blueprint $table) use ($hasCurrentAddressColumn, $hasPermanentAddressColumn): void {
            if ($hasCurrentAddressColumn) {
                $table->dropColumn('current_address');
            }

            if ($hasPermanentAddressColumn) {
                $table->dropColumn('permanent_address');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $hasCurrentAddressColumn = Schema::hasColumn('employees', 'current_address');
        $hasPermanentAddressColumn = Schema::hasColumn('employees', 'permanent_address');

        Schema::table('employees', function (Blueprint $table) use ($hasCurrentAddressColumn, $hasPermanentAddressColumn): void {
            if (! $hasCurrentAddressColumn) {
                $table->text('current_address')->nullable();
            }

            if (! $hasPermanentAddressColumn) {
                $table->text('permanent_address')->nullable();
            }
        });

        if (! Schema::hasTable('employee_addresses')) {
            return;
        }

        DB::table('employee_addresses')
            ->select('employee_id', 'address_type', 'address_line', 'is_primary', 'id')
            ->whereIn('address_type', [
                EmployeeAddressType::Current->value,
                EmployeeAddressType::Permanent->value,
            ])
            ->orderBy('employee_id')
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->chunkById(500, function (Collection $addresses): void {
                $addressesByEmployee = $addresses->groupBy('employee_id');

                foreach ($addressesByEmployee as $employeeId => $employeeAddresses) {
                    $currentAddress = $employeeAddresses
                        ->firstWhere('address_type', EmployeeAddressType::Current->value)?->address_line;
                    $permanentAddress = $employeeAddresses
                        ->firstWhere('address_type', EmployeeAddressType::Permanent->value)?->address_line;

                    DB::table('employees')
                        ->where('id', $employeeId)
                        ->update([
                            'current_address' => $currentAddress,
                            'permanent_address' => $permanentAddress,
                        ]);
                }
            });
    }
};
