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
        Schema::table('payout_accounts', function (Blueprint $table) {
            /**
             * Whatever each provider issued for this account, keyed by gateway name —
             * a Paystack recipient code, for instance. Keyed rather than a single
             * column because an account outlives a change of provider, and a code
             * minted by one provider is meaningless to another.
             */
            $table->json('recipient_tokens')->default('{}');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payout_accounts', function (Blueprint $table) {
            $table->dropColumn('recipient_tokens');
        });
    }
};
