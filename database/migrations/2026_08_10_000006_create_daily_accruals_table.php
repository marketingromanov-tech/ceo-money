<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_accruals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investment_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('investment_lot_id')->constrained()->cascadeOnDelete();
            $table->date('accrual_date');
            $table->decimal('principal_amount', 18, 8);
            $table->decimal('monthly_rate', 8, 4);
            $table->unsignedTinyInteger('days_in_month');
            $table->decimal('calculated_amount', 18, 8);
            $table->decimal('adjustment_amount', 18, 8)->default(0);
            $table->decimal('final_amount', 18, 8);
            $table->string('status')->default('calculated');
            $table->dateTime('calculated_at')->nullable();
            $table->timestamps();

            $table->unique(['investment_lot_id', 'accrual_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_accruals');
    }
};
