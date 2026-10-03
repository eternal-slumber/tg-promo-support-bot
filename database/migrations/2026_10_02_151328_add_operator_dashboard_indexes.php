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
            $table->index(['created_at', 'id']);
            $table->index(['status', 'created_at', 'id']);
        });
        DB::statement("CREATE INDEX tickets_active_created_at_id_index ON tickets (created_at, id) WHERE status IN ('open', 'waiting_for_user')");

        Schema::table('messages', function (Blueprint $table) {
            $table->index(['ticket_id', 'created_at', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['ticket_id', 'created_at', 'id']);
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex('tickets_active_created_at_id_index');
            $table->dropIndex(['status', 'created_at', 'id']);
            $table->dropIndex(['created_at', 'id']);
        });
    }
};
