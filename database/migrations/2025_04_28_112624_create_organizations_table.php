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
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('organization_code')->unique()->nullable();
            $table->string('email')->unique()->nullable(); // Orgs might not need direct login initially
            $table->string('password')->nullable();      // Make nullable if no direct login
            $table->rememberToken(); // Keep for potential future login
            // Add other org details if needed (address, contact person, etc.)
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
