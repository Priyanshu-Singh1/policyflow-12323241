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
        Schema::create('policy_state_histories', function (Blueprint $table) {
            $table->id();
            $table->uuid('policy_request_id');
            $table->string('from_state', 50);
            $table->string('to_state', 50);
            $table->string('reason', 255);
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('policy_request_id')
                ->references('id')
                ->on('policy_requests')
                ->onDelete('cascade');

            $table->index(['policy_request_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('policy_state_histories');
    }
};
