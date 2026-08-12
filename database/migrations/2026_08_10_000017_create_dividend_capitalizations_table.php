<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dividend_capitalizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('investment_account_id')->constrained()->cascadeOnDelete();
            $table->decimal('requested_amount', 18, 8);
            $table->foreignId('fee_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('fee_amount', 18, 8)->default(0);
            $table->string('fee_payer')->nullable();
            $table->decimal('capitalized_amount', 18, 8);
            $table->string('currency')->default('USDT');
            $table->string('status')->default('completed');
            $table->foreignId('investment_lot_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('investment_transaction_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('capitalized_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dividend_capitalizations');
    }
};
