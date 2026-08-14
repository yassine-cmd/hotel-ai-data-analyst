<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (config('business_context.title_max') + config('business_context.content_max')
            > config('business_context.total_max')) {
            \Illuminate\Support\Facades\Log::warning(
                'business_context: title_max + content_max exceeds the fixed total_max ('.
                config('business_context.total_max').') — large single entries will be rejected by the total validator.'
            );
        }
    }
}
