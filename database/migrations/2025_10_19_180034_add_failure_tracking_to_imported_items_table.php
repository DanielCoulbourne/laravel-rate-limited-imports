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
        Schema::table('imported_items', function (Blueprint $table) {
            $table->timestamp('last_failed_at')->nullable()->after('price');
            $table->text('failure_reason')->nullable()->after('last_failed_at');
            $table->integer('failure_count')->default(0)->after('failure_reason');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('imported_items', function (Blueprint $table) {
            $table->dropColumn(['last_failed_at', 'failure_reason', 'failure_count']);
        });
    }
};
