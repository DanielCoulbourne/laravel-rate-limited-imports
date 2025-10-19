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
        Schema::create('import_item_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->constrained()->cascadeOnDelete();

            // Polymorphic relationship to any model
            $table->string('importable_type');
            $table->unsignedBigInteger('importable_id');
            $table->index(['importable_type', 'importable_id']);

            // External API identifier
            $table->string('external_id');

            // Import status
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');

            // Failure tracking
            $table->timestamp('last_failed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->integer('failure_count')->default(0);

            // Completion tracking
            $table->timestamp('completed_at')->nullable();

            // Flexible metadata storage
            $table->json('metadata')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('import_item_statuses');
    }
};
