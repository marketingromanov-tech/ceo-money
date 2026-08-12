<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('investment_programs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('status')->default('draft')->index();
            $table->string('currency')->index();
            $table->decimal('min_amount', 18, 8);
            $table->decimal('max_amount', 18, 8)->nullable();
            $table->boolean('is_partial_withdrawal_allowed')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('investment_program_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investment_program_id')->constrained()->cascadeOnDelete();
            $table->decimal('monthly_rate', 8, 4);
            $table->unsignedInteger('lock_months')->default(0);
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['investment_program_id', 'valid_from'], 'program_versions_program_date_idx');
        });

        Schema::table('investment_lots', function (Blueprint $table) {
            $table->foreignId('investment_program_id')->nullable()->after('investment_account_id')->constrained()->nullOnDelete();
            $table->string('investment_program_name_snapshot')->nullable()->after('investment_program_id');
            $table->string('investment_program_version_snapshot')->nullable()->after('investment_program_name_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('investment_lots', function (Blueprint $table) {
            $table->dropConstrainedForeignId('investment_program_id');
            $table->dropColumn(['investment_program_name_snapshot', 'investment_program_version_snapshot']);
        });
        Schema::dropIfExists('investment_program_versions');
        Schema::dropIfExists('investment_programs');
    }
};
