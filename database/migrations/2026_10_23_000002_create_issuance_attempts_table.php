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
        Schema::create('issuance_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('policy_request_id');
            $table->unsignedInteger('attempt_number');
            $table->string('idempotency_key')->index();
            $table->string('provider_reference', 100)->nullable()->index();
            $table->string('scenario', 50)->nullable();
            $table->json('request_payload');
            $table->json('response_payload')->nullable();
            $table->string('status', 50);
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->foreign('policy_request_id')
                ->references('id')
                ->on('policy_requests')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('issuance_attempts');
    }
};
