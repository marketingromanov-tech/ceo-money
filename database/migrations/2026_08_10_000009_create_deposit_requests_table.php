<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deposit_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('investment_account_id')->constrained()->cascadeOnDelete();
            $table->decimal('requested_amount', 18, 8);
            $table->decimal('received_amount', 18, 8)->nullable();
            $table->string('currency')->default('USDT');
            $table->string('network')->nullable();
            $table->string('txid')->nullable();
            $table->foreignId('fee_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('fee_amount', 18, 8)->default(0);
            $table->string('fee_payer')->nullable();
            $table->decimal('net_investment_amount', 18, 8)->nullable();
            $table->string('status')->default('pending');
            $table->dateTime('requested_at');
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('rejected_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejected_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void { Schema::dropIfExists('deposit_requests'); }
};
