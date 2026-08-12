<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dividend_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('withdrawal_request_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('investment_account_id')->constrained()->cascadeOnDelete();
            $table->decimal('gross_amount', 18, 8);
            $table->decimal('fee_amount', 18, 8)->default(0);
            $table->decimal('net_amount', 18, 8);
            $table->string('currency');
            $table->string('wallet_address')->nullable();
            $table->string('network')->nullable();
            $table->string('txid')->nullable();
            $table->dateTime('paid_at');
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void { Schema::dropIfExists('dividend_payments'); }
};
