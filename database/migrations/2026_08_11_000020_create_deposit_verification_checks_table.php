<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deposit_verification_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deposit_request_id')->constrained()->cascadeOnDelete();
            $table->string('check_key');
            $table->string('status')->default('pending');
            $table->foreignId('checked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('checked_at')->nullable();
            $table->json('value_snapshot')->nullable();
            $table->timestamps();
            $table->unique(['deposit_request_id', 'check_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deposit_verification_checks');
    }
};
