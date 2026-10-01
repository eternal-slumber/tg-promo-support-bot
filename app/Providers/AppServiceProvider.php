<?php

namespace App\Providers;

use App\Services\OpenAiLlmClient;
use App\Services\SupportLlmClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(SupportLlmClient::class, OpenAiLlmClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
