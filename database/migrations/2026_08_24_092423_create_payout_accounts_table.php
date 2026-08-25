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
        Schema::create('payout_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /**
             * Bank codes are provider-specific and are passed to the gateway
             * unchanged, so the code stored here must match the configured provider.
             */
            $table->string('bank_code');
            $table->string('bank_name');
            $table->string('account_number');
            $table->string('account_name');
            $table->char('currency', 3)->default('NGN');

            /** The account payouts go to when a user has registered more than one. */
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            // The same account cannot be registered twice for one user.
            $table->unique(['user_id', 'bank_code', 'account_number']);
            $table->index(['user_id', 'is_default']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payout_accounts');
    }
};
