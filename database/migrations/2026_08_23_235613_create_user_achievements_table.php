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
        Schema::create('user_achievements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /**
             * The catalog row is snapshotted rather than referenced by foreign key, so
             * renaming or retuning an achievement never rewrites a user's history.
             */
            $table->string('achievement_key');
            $table->string('achievement_name');
            $table->string('group_key');
            $table->unsignedInteger('threshold');
            $table->timestamp('unlocked_at');
            $table->timestamps();

            // An achievement can only be unlocked once per user; this is the guard that
            // makes a replayed order event idempotent.
            $table->unique(['user_id', 'achievement_key']);
            $table->index(['user_id', 'group_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_achievements');
    }
};
