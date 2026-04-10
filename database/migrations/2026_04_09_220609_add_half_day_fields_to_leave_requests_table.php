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
        if (! Schema::hasColumn('leave_requests', 'duration_type')) {
            Schema::table('leave_requests', function (Blueprint $table) {
                $table->string('duration_type', 20)->default('full_day')->after('reason');
            });
        }

        if (! Schema::hasColumn('leave_requests', 'half_day_session')) {
            Schema::table('leave_requests', function (Blueprint $table) {
                $table->string('half_day_session', 2)->nullable()->after('duration_type');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('leave_requests', 'half_day_session')) {
            Schema::table('leave_requests', function (Blueprint $table) {
                $table->dropColumn('half_day_session');
            });
        }

        if (Schema::hasColumn('leave_requests', 'duration_type')) {
            Schema::table('leave_requests', function (Blueprint $table) {
                $table->dropColumn('duration_type');
            });
        }
    }
};
