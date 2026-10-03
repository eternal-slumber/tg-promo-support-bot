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
        DB::table('tickets')->where('status', 'waiting_for_user')
            ->update(['status' => 'open', 'waiting_since' => null]);

        Schema::table('tickets', function (Blueprint $table) {
            $table->renameColumn('waiting_since', 'resolved_since');
            $table->dropColumn('input_revision');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->boolean('resolves_ticket')->default(false);
            $table->dropColumn('operator_input_revision');
        });

        DB::statement('DROP INDEX tickets_one_active_per_participant');
        DB::statement("CREATE UNIQUE INDEX tickets_one_active_per_participant ON tickets (participant_id) WHERE status IN ('open', 'resolved')");
        DB::statement('DROP INDEX tickets_active_created_at_id_index');
        DB::statement("CREATE INDEX tickets_active_created_at_id_index ON tickets (created_at, id) WHERE status IN ('open', 'resolved')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('tickets')->where('status', 'resolved')->update(['status' => 'waiting_for_user']);

        Schema::table('tickets', function (Blueprint $table) {
            $table->renameColumn('resolved_since', 'waiting_since');
            $table->unsignedBigInteger('input_revision')->default(0);
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->unsignedBigInteger('operator_input_revision')->default(0);
            $table->dropColumn('resolves_ticket');
        });

        DB::statement("UPDATE tickets SET input_revision = (SELECT COUNT(*) FROM messages WHERE messages.ticket_id = tickets.id AND direction = 'inbound' AND author = 'participant')");
        DB::statement('DROP INDEX tickets_one_active_per_participant');
        DB::statement("CREATE UNIQUE INDEX tickets_one_active_per_participant ON tickets (participant_id) WHERE status IN ('open', 'waiting_for_user')");
        DB::statement('DROP INDEX tickets_active_created_at_id_index');
        DB::statement("CREATE INDEX tickets_active_created_at_id_index ON tickets (created_at, id) WHERE status IN ('open', 'waiting_for_user')");
    }
};
