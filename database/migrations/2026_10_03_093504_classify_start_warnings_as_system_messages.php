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
        DB::table('messages')
            ->where('direction', 'outbound')
            ->where('author', 'bot')
            ->where('body', 'Не отправляйте данные банковских карт, пароли или коды из SMS: они не нужны для поддержки акции.')
            ->update(['author' => 'system']);
    }

    /**
     * Historical system warnings retain their corrected classification on rollback.
     */
    public function down(): void {}
};
