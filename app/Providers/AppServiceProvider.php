<?php

namespace App\Providers;

use App\Services\SQLValidationService;
use App\Services\TogetherAIService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TogetherAIService::class, function ($app) {
            return new TogetherAIService();
        });

        // --- AÑADIR ESTE BLOQUE ---
        $this->app->singleton(SQLValidationService::class, function ($app) {
            return new SQLValidationService();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
