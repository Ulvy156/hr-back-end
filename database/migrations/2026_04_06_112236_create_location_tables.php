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
        Schema::create('provinces', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('source_id')->unique();
            $table->string('code', 10)->unique();
            $table->string('name_kh', 255);
            $table->string('name_en', 255);
        });

        Schema::create('districts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('source_id')->unique();
            $table->string('code', 10)->unique();
            $table->foreignId('province_id')->constrained()->restrictOnDelete();
            $table->string('name_kh', 255);
            $table->string('name_en', 255);
            $table->string('type', 50)->nullable();
        });

        Schema::create('communes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('source_id')->unique();
            $table->string('code', 10)->unique();
            $table->foreignId('district_id')->constrained()->restrictOnDelete();
            $table->string('name_kh', 255);
            $table->string('name_en', 255);
        });

        Schema::create('villages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('source_id')->unique();
            $table->string('code', 10)->unique();
            $table->foreignId('commune_id')->constrained()->restrictOnDelete();
            $table->string('name_kh', 255);
            $table->string('name_en', 255);
            $table->boolean('is_not_active')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('villages');
        Schema::dropIfExists('communes');
        Schema::dropIfExists('districts');
        Schema::dropIfExists('provinces');
    }
};
