<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('capital_withdrawal_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('withdrawal_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('investment_lot_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 18, 8);
            $table->timestamps();
            $table->unique(['withdrawal_request_id', 'investment_lot_id'], 'capital_allocation_request_lot_unique');
        });
    }

    public function down(): void { Schema::dropIfExists('capital_withdrawal_allocations'); }
};
