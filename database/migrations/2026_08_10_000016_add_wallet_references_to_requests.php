<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deposit_requests', function (Blueprint $table) {
            $table->foreignId('deposit_address_id')->nullable()->after('network')->constrained()->nullOnDelete();
            $table->string('deposit_address_snapshot')->nullable()->after('deposit_address_id');
            $table->string('provider_snapshot')->nullable()->after('deposit_address_snapshot');
        });

        Schema::table('withdrawal_requests', function (Blueprint $table) {
            $table->foreignId('investor_wallet_id')->nullable()->after('currency')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('withdrawal_requests', fn (Blueprint $table) => $table->dropConstrainedForeignId('investor_wallet_id'));
        Schema::table('deposit_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deposit_address_id');
            $table->dropColumn(['deposit_address_snapshot', 'provider_snapshot']);
        });
    }
};
