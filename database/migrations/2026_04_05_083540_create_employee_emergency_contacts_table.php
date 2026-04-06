<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('employee_emergency_contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->index()->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('relationship', 100);
            $table->string('phone', 30);
            $table->string('email')->nullable();
            $table->boolean('is_primary')->default(false)->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_emergency_contacts');
    }
};
