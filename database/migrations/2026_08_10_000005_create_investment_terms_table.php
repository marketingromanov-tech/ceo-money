<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investment_terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investment_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('investment_lot_id')->nullable()->constrained()->cascadeOnDelete();
            $table->decimal('monthly_rate', 8, 4);
            $table->unsignedInteger('lock_months')->default(0);
            $table->decimal('minimum_balance', 18, 8)->default(0);
            $table->boolean('partial_withdrawal_allowed')->default(true);
            $table->decimal('minimum_dividend_withdrawal', 18, 8)->default(0);
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investment_terms');
    }
};
