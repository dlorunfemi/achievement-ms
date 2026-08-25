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
        Schema::create('user_badges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /** Snapshotted from the catalog for the same reason as user_achievements. */
            $table->string('badge_key');
            $table->string('badge_name');
            $table->unsignedInteger('threshold');
            $table->timestamp('unlocked_at');
            $table->timestamps();

            // Guarantees at most one badge unlock per user per badge, which in turn
            // guarantees at most one cashback.
            $table->unique(['user_id', 'badge_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_badges');
    }
};
