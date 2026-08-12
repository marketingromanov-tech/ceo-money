<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('category'); $table->string('subject');
            $table->string('status')->default('new')->index();
            $table->string('priority')->default('normal')->index();
            $table->string('related_type')->nullable(); $table->unsignedBigInteger('related_id')->nullable();
            $table->index(['related_type','related_id']);
            $table->dateTime('investor_last_read_at')->nullable(); $table->dateTime('admin_last_read_at')->nullable();
            $table->dateTime('last_message_at')->index(); $table->dateTime('resolved_at')->nullable(); $table->dateTime('closed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('support_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained()->cascadeOnDelete()->index();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->text('message'); $table->boolean('is_internal')->default(false);
            $table->timestamps(); $table->index(['support_ticket_id','created_at']);
        });
    }
    public function down(): void { Schema::dropIfExists('support_messages'); Schema::dropIfExists('support_tickets'); }
};
