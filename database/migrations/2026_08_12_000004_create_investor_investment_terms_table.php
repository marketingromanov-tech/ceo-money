<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investor_investment_terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investor_id')->constrained('users')->cascadeOnDelete();
            $table->string('currency')->default('USDT');
            $table->decimal('min_amount', 20, 8)->nullable();
            $table->decimal('max_amount', 20, 8)->nullable();
            $table->decimal('monthly_rate', 10, 4);
            $table->unsignedInteger('term_months');
            $table->unsignedInteger('lock_days')->nullable();
            $table->boolean('partial_withdrawal')->default(false);
            $table->date('starts_at');
            $table->date('ends_at')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->text('notes')->nullable();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['investor_id', 'currency', 'status'], 'investor_terms_lookup');
            $table->index(['starts_at', 'ends_at'], 'investor_terms_validity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investor_investment_terms');
    }
};
