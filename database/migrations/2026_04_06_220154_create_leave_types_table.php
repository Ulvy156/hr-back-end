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
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->boolean('is_paid')->default(false);
            $table->boolean('requires_balance')->default(false);
            $table->boolean('requires_attachment')->default(false);
            $table->boolean('requires_medical_certificate')->default(false);
            $table->boolean('auto_exclude_public_holidays')->default(false);
            $table->boolean('auto_exclude_weekends')->default(false);
            $table->string('gender_restriction', 20)->nullable();
            $table->unsignedInteger('min_service_days')->nullable();
            $table->unsignedInteger('max_days_per_request')->nullable();
            $table->unsignedInteger('max_days_per_year')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE leave_types ADD CONSTRAINT leave_types_gender_restriction_check CHECK (gender_restriction IS NULL OR gender_restriction IN ('none', 'male', 'female'))"
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_types');
    }
};
