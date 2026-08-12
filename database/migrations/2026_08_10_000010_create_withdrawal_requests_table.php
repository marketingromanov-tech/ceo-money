<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('withdrawal_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('investment_account_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->decimal('requested_amount', 18, 8);
            $table->decimal('reserved_amount', 18, 8)->default(0);
            $table->foreignId('fee_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('fee_amount', 18, 8)->default(0);
            $table->string('fee_payer')->nullable();
            $table->decimal('net_amount', 18, 8)->nullable();
            $table->string('currency')->default('USDT');
            $table->string('wallet_address_snapshot')->nullable();
            $table->string('network_snapshot')->nullable();
            $table->string('status')->default('new');
            $table->string('txid')->nullable();
            $table->dateTime('requested_at');
            $table->dateTime('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('paid_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('rejected_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejected_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void { Schema::dropIfExists('withdrawal_requests'); }
};
