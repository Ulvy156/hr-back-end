<?php

use App\EmployeeAddressType;
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
        Schema::create('employee_addresses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->index()->constrained()->cascadeOnDelete();
            $table->enum('address_type', array_column(EmployeeAddressType::cases(), 'value'))->index();
            $table->foreignId('province_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('district_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('commune_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('village_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('address_line', 255)->nullable();
            $table->string('street', 150)->nullable();
            $table->string('house_no', 50)->nullable();
            $table->string('postal_code', 30)->nullable();
            $table->text('note')->nullable();
            $table->boolean('is_primary')->default(false)->index();
            $table->timestamps();

            $table->index(['employee_id', 'address_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_addresses');
    }
};
