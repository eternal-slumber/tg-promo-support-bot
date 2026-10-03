<?php

namespace App\Providers;

use App\Services\OpenAiLlmClient;
use App\Services\SupportLlmClient;
use App\Services\TelegramBotApiClient;
use App\Services\TelegramBotClient;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(SupportLlmClient::class, OpenAiLlmClient::class);
        $this->app->bind(TelegramBotClient::class, TelegramBotApiClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        DevCommands::artisan('queue:listen database --queue=ai,default --sleep=1 --tries=3 --timeout=150', 'queue');
    }
}
