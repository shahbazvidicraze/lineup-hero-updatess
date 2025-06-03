<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promo_code_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('promo_code_id')->constrained('promo_codes')->cascadeOnDelete();
            $table->string('user_organization_access_code')->nullable();
            $table->timestamp('redeemed_at');
            // Removed unique constraint to allow multiple redemptions if max_uses_per_user > 1 or team_id is used
            // Add back if needed: $table->unique(['user_id', 'promo_code_id', 'team_id']); // Adjust uniqueness based on rules

            // No need for timestamps() here unless tracking updates to the redemption record itself
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_code_redemptions');
    }
};
