<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fee_rules', function (Blueprint $table) {
            $table->string('economic_type')->nullable()->after('payer');
        });

        Schema::table('deposit_requests', function (Blueprint $table) {
            $table->string('fee_economic_type_snapshot')->nullable()->after('fee_payer');
        });

        Schema::table('withdrawal_requests', function (Blueprint $table) {
            $table->string('fee_economic_type_snapshot')->nullable()->after('fee_payer');
        });

        Schema::table('dividend_capitalizations', function (Blueprint $table) {
            $table->string('fee_economic_type_snapshot')->nullable()->after('fee_payer');
        });
    }

    public function down(): void
    {
        Schema::table('dividend_capitalizations', fn (Blueprint $table) => $table->dropColumn('fee_economic_type_snapshot'));
        Schema::table('withdrawal_requests', fn (Blueprint $table) => $table->dropColumn('fee_economic_type_snapshot'));
        Schema::table('deposit_requests', fn (Blueprint $table) => $table->dropColumn('fee_economic_type_snapshot'));
        Schema::table('fee_rules', fn (Blueprint $table) => $table->dropColumn('economic_type'));
    }
};
