<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::create('backup_records', function (Blueprint $table) {
        $table->id(); $table->string('type')->default('database'); $table->string('filename')->unique();
        $table->string('storage_disk'); $table->string('storage_path'); $table->unsignedBigInteger('size_bytes')->nullable();
        $table->string('sha256', 64)->nullable(); $table->string('status'); $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
        $table->timestamp('started_at'); $table->timestamp('completed_at')->nullable(); $table->timestamp('verified_at')->nullable();
        $table->text('failure_message')->nullable(); $table->string('database_name')->nullable(); $table->string('application_commit')->nullable(); $table->timestamps();
        $table->index(['status','created_at']);
    }); }
    public function down(): void { Schema::dropIfExists('backup_records'); }
};
