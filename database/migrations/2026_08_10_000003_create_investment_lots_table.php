<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investment_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investment_account_id')->constrained()->cascadeOnDelete();
            $table->decimal('original_amount', 18, 8);
            $table->decimal('remaining_amount', 18, 8);
            $table->string('currency')->default('USDT');
            $table->dateTime('received_at');
            $table->date('accrual_start_date');
            $table->unsignedInteger('lock_months')->default(0);
            $table->date('unlock_date')->nullable();
            $table->decimal('monthly_rate', 8, 4);
            $table->string('status')->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investment_lots');
    }
};
