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
        Schema::create('policy_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('client_request_id');
            $table->string('agent_id');
            $table->string('customer_name');
            $table->date('date_of_birth');
            $table->string('destination', 30);
            $table->date('trip_start');
            $table->date('trip_end');
            $table->string('plan_code', 30);
            $table->unsignedBigInteger('sum_insured');
            $table->unsignedInteger('travel_days');
            $table->decimal('premium_amount', 10, 2);
            $table->string('currency', 3)->default('INR');
            $table->string('status', 50)->default('RECEIVED');
            $table->string('policy_number', 100)->nullable()->unique();
            $table->string('provider_reference', 100)->nullable()->index();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->json('last_response')->nullable();
            $table->timestamps();

            // Composite uniqueness constraint prevents duplicate intake at the database layer
            $table->unique(['agent_id', 'client_request_id'], 'unique_agent_client_request');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('policy_requests');
    }
};
