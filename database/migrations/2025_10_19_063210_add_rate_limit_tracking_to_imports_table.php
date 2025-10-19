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
        Schema::table('imports', function (Blueprint $table) {
            $table->unsignedInteger('rate_limit_hits_count')->default(0)->after('items_imported_count');
            $table->unsignedInteger('rate_limit_sleeps_count')->default(0)->after('rate_limit_hits_count');
            $table->unsignedInteger('total_sleep_seconds')->default(0)->after('rate_limit_sleeps_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('imports', function (Blueprint $table) {
            $table->dropColumn([
                'rate_limit_hits_count',
                'rate_limit_sleeps_count',
                'total_sleep_seconds',
            ]);
        });
    }
};
