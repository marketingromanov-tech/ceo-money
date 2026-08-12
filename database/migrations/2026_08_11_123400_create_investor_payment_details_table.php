<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investor_payment_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investor_id')->constrained()->cascadeOnDelete();
            $table->string('currency');
            $table->string('network');
            $table->string('address');
            $table->string('memo')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['investor_id', 'currency', 'is_active'], 'investor_payment_details_lookup_idx');
        });

        Schema::table('deposit_requests', function (Blueprint $table) {
            $table->json('payment_details_snapshot')
                ->nullable()
                ->after('investment_program_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('deposit_requests', function (Blueprint $table) {
            $table->dropColumn('payment_details_snapshot');
        });

        Schema::dropIfExists('investor_payment_details');
    }
};
