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
        Schema::create('achievements', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');

            /**
             * Achievements belong to a progression group (e.g. "purchases"). Within a
             * group they are ordered by threshold, and the endpoint surfaces only the
             * next unearned one per group. Adding a group adds no code.
             */
            $table->string('group_key');
            $table->unsignedInteger('threshold');
            $table->timestamps();

            $table->unique(['group_key', 'threshold']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('achievements');
    }
};
