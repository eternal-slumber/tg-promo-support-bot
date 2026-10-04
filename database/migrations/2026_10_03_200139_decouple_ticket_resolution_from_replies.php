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
        DB::table('tickets')->where('status', 'resolved')
            ->update(['status' => 'open', 'resolved_since' => null]);

        Schema::table('tickets', function (Blueprint $table) {
            $table->timestamp('resolved_since', 6)->nullable()->change();
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('resolves_ticket');
        });
    }

    /**
     * Restore the schema; reopened tickets and removed reply intents cannot be restored.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->boolean('resolves_ticket')->default(false);
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->timestamp('resolved_since')->nullable()->change();
        });
    }
};
