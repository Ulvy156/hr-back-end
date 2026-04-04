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
        Schema::create('salary_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_id')->index()->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('type', 20);
            $table->decimal('amount', 12, 2);
            $table->timestamps();
            $table->index(['payroll_id', 'type']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE salary_components ADD CONSTRAINT salary_components_type_check CHECK (type IN ('allowance', 'deduction'))"
            );
            DB::statement(
                'ALTER TABLE salary_components ADD CONSTRAINT salary_components_amount_check CHECK (amount >= 0)'
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('salary_components');
    }
};
