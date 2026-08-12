<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investor_withdrawal_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investor_id')->constrained()->cascadeOnDelete();
            $table->string('currency');
            $table->string('network');
            $table->string('address');
            $table->string('memo')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['investor_id', 'currency', 'is_active'], 'investor_withdrawal_details_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investor_withdrawal_details');
    }
};
