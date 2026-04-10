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
        if (Schema::hasColumn('leave_requests', 'reason')) {
            return;
        }

        Schema::table('leave_requests', function (Blueprint $table) {
            $table->text('reason')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('leave_requests', 'reason')) {
            return;
        }

        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropColumn('reason');
        });
    }
};
