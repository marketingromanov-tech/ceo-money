<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investor_wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investor_id')->constrained()->cascadeOnDelete();
            $table->string('currency');
            $table->string('network');
            $table->string('address');
            $table->string('label')->nullable();
            $table->string('status')->default('pending');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('blocked_at')->nullable();
            $table->dateTime('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['investor_id', 'network', 'address'], 'investor_wallet_owner_network_address_unique');
        });
    }

    public function down(): void { Schema::dropIfExists('investor_wallets'); }
};
