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
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('current_position_id')->index()->constrained('positions')->restrictOnDelete();
            $table->foreignId('manager_id')->nullable()->index()->constrained('employees')->nullOnDelete();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('email')->unique();
            $table->string('phone', 30);
            $table->date('hire_date');
            $table->string('status', 20)->index();
            $table->timestamps();
            $table->softDeletes();
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE employees ADD CONSTRAINT employees_status_check CHECK (status IN ('active', 'inactive', 'terminated'))"
            );
            DB::statement(
                'ALTER TABLE employees ADD CONSTRAINT employees_manager_check CHECK (manager_id IS NULL OR manager_id <> id)'
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
