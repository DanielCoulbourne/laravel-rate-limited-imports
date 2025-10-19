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
            $table->timestamp('scheduled_at')->nullable()->after('importable_type');
            $table->timestamp('cancelled_at')->nullable()->after('ended_at');
            $table->string('status')->default('scheduled')->after('importable_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('imports', function (Blueprint $table) {
            $table->dropColumn(['scheduled_at', 'cancelled_at', 'status']);
        });
    }
};
