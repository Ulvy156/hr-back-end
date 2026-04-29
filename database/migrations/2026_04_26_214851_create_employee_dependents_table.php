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
        Schema::create('employee_dependents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->index()->constrained()->cascadeOnDelete();
            $table->string('name', 150)->nullable();
            $table->string('relationship', 20);
            $table->date('date_of_birth')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_working')->default(false);
            $table->boolean('is_student')->default(false);
            $table->boolean('is_claimed_for_tax')->default(true);
            $table->timestamps();

            $table->index(['employee_id', 'is_active', 'is_claimed_for_tax'], 'employee_dependents_employee_active_claimed_idx');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE employee_dependents ADD CONSTRAINT employee_dependents_relationship_check CHECK (relationship IN ('spouse', 'child'))"
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_dependents');
    }
};
