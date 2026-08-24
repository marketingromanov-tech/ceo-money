<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deposit_requests', function (Blueprint $table) {
            $table->string('effective_terms_source', 20)->nullable()->after('investment_program_snapshot');
            $table->json('effective_terms_snapshot')->nullable()->after('effective_terms_source');
        });
        Schema::table('investment_lots', function (Blueprint $table) {
            $table->string('effective_terms_source', 20)->nullable()->after('investment_program_version_snapshot');
            $table->json('effective_terms_snapshot')->nullable()->after('effective_terms_source');
        });
    }

    public function down(): void
    {
        Schema::table('deposit_requests', fn (Blueprint $table) => $table->dropColumn(['effective_terms_source', 'effective_terms_snapshot']));
        Schema::table('investment_lots', fn (Blueprint $table) => $table->dropColumn(['effective_terms_source', 'effective_terms_snapshot']));
    }
};
