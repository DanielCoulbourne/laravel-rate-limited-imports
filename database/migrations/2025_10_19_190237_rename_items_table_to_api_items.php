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
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Rename back to items
        Schema::rename('api_items', 'items');
    }
};
