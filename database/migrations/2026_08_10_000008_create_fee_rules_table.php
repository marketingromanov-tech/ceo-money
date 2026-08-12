<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_rules', function (Blueprint $table) {
            $table->id();
            $table->string('operation_type');
            $table->string('scope')->default('global');
            $table->foreignId('investor_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('currency')->nullable();
            $table->string('fee_type');
            $table->decimal('percent_value', 8, 4)->default(0);
            $table->decimal('fixed_value', 18, 8)->default(0);
            $table->string('payer')->default('investor');
            $table->decimal('minimum_fee', 18, 8)->nullable();
            $table->decimal('maximum_fee', 18, 8)->nullable();
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void { Schema::dropIfExists('fee_rules'); }
};
