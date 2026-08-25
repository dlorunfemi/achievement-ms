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
        Schema::create('cashbacks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_badge_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /** Minor units (kobo). The ₦300 badge reward is stored as 30000. */
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3)->default('NGN');
            $table->string('status')->default('pending');
            $table->string('gateway');
            $table->string('gateway_reference')->nullable();

            /**
             * Derived from the user badge, so a retried job or a replayed BadgeUnlocked
             * event resolves to the same key and cannot pay the user twice.
             */
            $table->string('idempotency_key')->unique();
            $table->text('failure_reason')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            // Drives the retry sweep over stuck or failed payouts.
            $table->index(['status', 'attempts']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cashbacks');
    }
};
