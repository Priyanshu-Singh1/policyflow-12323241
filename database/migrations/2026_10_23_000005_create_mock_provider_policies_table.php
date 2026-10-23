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
        Schema::create('mock_provider_policies', function (Blueprint $table) {
            $table->id();
            $table->string('provider_reference', 100)->unique();
            $table->string('idempotency_key', 255)->unique();
            $table->string('policy_number', 100)->unique();
            $table->string('customer_name');
            $table->decimal('premium_amount', 10, 2);
            $table->string('status', 50)->default('ACTIVE');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mock_provider_policies');
    }
};
