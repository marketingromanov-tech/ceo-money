<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('withdrawal_verification_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('withdrawal_request_id')->constrained()->cascadeOnDelete();
            $table->string('check_key');
            $table->string('status')->default('pending');
            $table->foreignId('checked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('checked_at')->nullable();
            $table->json('value_snapshot')->nullable();
            $table->timestamps();
            $table->unique(['withdrawal_request_id', 'check_key'], 'withdrawal_check_request_key_unique');
        });

        Schema::table('withdrawal_requests', function (Blueprint $table) {
            $table->text('cancellation_reason')->nullable()->after('cancelled_by');
        });
    }

    public function down(): void
    {
        Schema::table('withdrawal_requests', fn (Blueprint $table) => $table->dropColumn('cancellation_reason'));
        Schema::dropIfExists('withdrawal_verification_checks');
    }
};
