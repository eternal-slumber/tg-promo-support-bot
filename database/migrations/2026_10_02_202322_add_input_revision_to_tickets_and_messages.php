<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->unsignedBigInteger('input_revision')->default(0);
        });
        Schema::table('messages', function (Blueprint $table) {
            $table->unsignedBigInteger('operator_input_revision')->default(0);
        });
        DB::statement("UPDATE tickets SET input_revision = (SELECT COUNT(*) FROM messages WHERE messages.ticket_id = tickets.id AND direction = 'inbound' AND author = 'participant')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('operator_input_revision');
        });
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('input_revision');
        });
    }
};
