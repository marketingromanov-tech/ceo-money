<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investor_investment_term_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('investor_investment_term_id');
            $table->string('currency');
            $table->decimal('min_amount', 20, 8)->nullable();
            $table->decimal('max_amount', 20, 8)->nullable();
            $table->decimal('monthly_rate', 10, 4);
            $table->unsignedInteger('term_months');
            $table->unsignedInteger('lock_days')->nullable();
            $table->boolean('partial_withdrawal')->default(false);
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->unsignedBigInteger('created_by_admin_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['investor_investment_term_id', 'valid_from', 'valid_to'], 'individual_term_versions_validity');
            $table->foreign('investor_investment_term_id', 'iit_versions_term_fk')->references('id')->on('investor_investment_terms')->cascadeOnDelete();
            $table->foreign('created_by_admin_id', 'iit_versions_admin_fk')->references('id')->on('users')->nullOnDelete();
        });

        DB::table('investor_investment_terms')->orderBy('id')->get()->each(function ($term): void {
            DB::table('investor_investment_term_versions')->insert([
                'investor_investment_term_id' => $term->id,
                'currency' => $term->currency,
                'min_amount' => $term->min_amount,
                'max_amount' => $term->max_amount,
                'monthly_rate' => $term->monthly_rate,
                'term_months' => $term->term_months,
                'lock_days' => $term->lock_days,
                'partial_withdrawal' => $term->partial_withdrawal,
                'valid_from' => $term->starts_at,
                'valid_to' => $term->ends_at,
                'created_by_admin_id' => $term->created_by_admin_id,
                'notes' => $term->notes,
                'created_at' => $term->created_at,
                'updated_at' => $term->updated_at,
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investor_investment_term_versions');
    }
};
