<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('profile_photo', 2048)->nullable()->after('profile_photo_path');
        });

        DB::table('employees')
            ->whereNotNull('profile_photo_path')
            ->update([
                'profile_photo' => DB::raw('profile_photo_path'),
            ]);
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn('profile_photo');
        });
    }
};
