<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deposit_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investor_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider')->nullable();
            $table->string('currency');
            $table->string('network');
            $table->string('address');
            $table->string('label')->nullable();
            $table->boolean('is_personal')->default(false);
            $table->boolean('is_active')->default(true);
            $table->dateTime('assigned_at')->nullable();
            $table->dateTime('archived_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['address', 'network'], 'deposit_addresses_address_network_unique');
        });
    }

    public function down(): void { Schema::dropIfExists('deposit_addresses'); }
};
