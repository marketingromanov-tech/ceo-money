<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicateTxids = DB::table('withdrawal_requests')
            ->selectRaw('LOWER(TRIM(txid)) as normalized_txid, COUNT(*) as aggregate')
            ->whereNotNull('txid')
            ->whereRaw("TRIM(txid) <> ''")
            ->groupByRaw('LOWER(TRIM(txid))')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicateTxids) {
            throw new RuntimeException('Cannot add withdrawal TXID uniqueness: duplicate normalized TXIDs exist.');
        }

        Schema::table('withdrawal_requests', function (Blueprint $table) {
            $table->unique('txid', 'withdrawal_requests_txid_unique');
        });

        Schema::table('investment_lots', function (Blueprint $table) {
            $table->foreignId('deposit_request_id')->nullable()->after('investment_account_id')
                ->constrained('deposit_requests')->nullOnDelete();
            $table->unique('deposit_request_id');
        });

        Schema::table('investment_transactions', function (Blueprint $table) {
            $table->foreignId('deposit_request_id')->nullable()->after('investment_lot_id')
                ->constrained('deposit_requests')->nullOnDelete();
            $table->foreignId('withdrawal_request_id')->nullable()->after('deposit_request_id')
                ->constrained('withdrawal_requests')->nullOnDelete();
            $table->unique('deposit_request_id');
            $table->unique('withdrawal_request_id');
        });
    }

    public function down(): void
    {
        Schema::table('investment_transactions', function (Blueprint $table) {
            $table->dropForeign(['deposit_request_id']);
            $table->dropForeign(['withdrawal_request_id']);
            $table->dropUnique(['deposit_request_id']);
            $table->dropUnique(['withdrawal_request_id']);
            $table->dropColumn(['deposit_request_id', 'withdrawal_request_id']);
        });

        Schema::table('investment_lots', function (Blueprint $table) {
            $table->dropForeign(['deposit_request_id']);
            $table->dropUnique(['deposit_request_id']);
            $table->dropColumn('deposit_request_id');
        });

        Schema::table('withdrawal_requests', function (Blueprint $table) {
            $table->dropUnique('withdrawal_requests_txid_unique');
        });
    }
};
