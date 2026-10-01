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
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('participant_id')->constrained('telegram_participants')->cascadeOnDelete();
            $table->string('status')->default('open');
            $table->string('escalation_reason')->nullable();
            $table->timestamp('first_operator_replied_at')->nullable();
            $table->timestamp('waiting_since')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('close_reason')->nullable();
            $table->timestamps();
        });

        DB::statement("CREATE UNIQUE INDEX tickets_one_active_per_participant ON tickets (participant_id) WHERE status IN ('open', 'waiting_for_user')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
