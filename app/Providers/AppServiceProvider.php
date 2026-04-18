<?php

namespace App\Providers;

use App\Models\LeadReengagementEvent;
use Illuminate\Support\Facades\View;
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
        View::composer('partials.app-nav', function ($view) {
            $unseenReengagementCount = LeadReengagementEvent::query()
                ->whereNull('seen_at')
                ->whereNotNull('lead_id')
                ->whereHas('lead')
                ->count();

            $view->with('unseenReengagementCount', $unseenReengagementCount);
        });
    }
}
