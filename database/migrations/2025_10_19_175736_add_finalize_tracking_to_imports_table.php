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
            $table->integer('finalize_attempts')->default(0)->after('total_sleep_seconds');
            $table->timestamp('last_finalize_attempt_at')->nullable()->after('finalize_attempts');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('imports', function (Blueprint $table) {
            $table->dropColumn(['finalize_attempts', 'last_finalize_attempt_at']);
        });
    }
};
