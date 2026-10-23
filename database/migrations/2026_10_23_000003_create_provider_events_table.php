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
        Schema::create('provider_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('event_id')->unique(); // Database-level deduplication for callbacks
            $table->string('provider_reference', 100)->index();
            $table->uuid('policy_request_id')->nullable();
            $table->string('event_type', 50);
            $table->json('payload');
            $table->string('status', 50)->default('PROCESSED');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->foreign('policy_request_id')
                ->references('id')
                ->on('policy_requests')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('provider_events');
    }
};
