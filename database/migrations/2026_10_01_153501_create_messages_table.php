<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('participant_id')->constrained('telegram_participants')->cascadeOnDelete();
            $table->foreignId('ticket_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('telegram_update_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('operator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('direction');
            $table->string('author');
            $table->text('body');
            $table->string('delivery_status')->nullable();
            $table->bigInteger('telegram_message_id')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->unsignedInteger('delivery_attempts')->default(0);
            $table->string('last_delivery_error')->nullable();
            $table->boolean('sensitive_data_redacted')->default(false);
            $table->jsonb('redaction_types')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
