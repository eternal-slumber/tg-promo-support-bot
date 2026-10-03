<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::statement('LOCK TABLE tickets IN EXCLUSIVE MODE');
            DB::statement(<<<'SQL'
                UPDATE tickets
                SET first_operator_replied_at = (
                    SELECT MIN(messages.delivered_at)
                    FROM messages
                    WHERE messages.ticket_id = tickets.id
                        AND messages.direction = 'outbound'
                        AND messages.author = 'operator'
                        AND messages.delivery_status = 'sent'
                )
                SQL);
        });
    }

    /**
     * This data correction cannot restore the original preparation timestamps.
     */
    public function down(): void {}
};
