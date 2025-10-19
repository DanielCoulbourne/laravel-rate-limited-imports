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
        // Rename items table to api_items
        Schema::rename('items', 'api_items');

        // Remove external_id column - api_items are the source, not imported
        Schema::table('api_items', function (Blueprint $table) {
            $table->dropColumn('external_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Add external_id back
        Schema::table('api_items', function (Blueprint $table) {
            $table->string('external_id')->nullable()->after('id');
        });

        // Rename back to items
        Schema::rename('api_items', 'items');
    }
};
