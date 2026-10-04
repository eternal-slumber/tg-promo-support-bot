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
        Schema::table('tickets', function (Blueprint $table) {
            $table->jsonb('context_message_ids')->nullable();
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('source_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->string('ticket_event')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_message_id');
            $table->dropColumn('ticket_event');
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('context_message_ids');
        });
    }
};
