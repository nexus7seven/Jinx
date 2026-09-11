<?php

namespace App\Providers;

use App\Http\Controllers\JinxAssistantController;
use App\Models\LeadReengagementEvent;
use Illuminate\Support\Facades\Route;
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

        Route::middleware(['web', 'auth'])
            ->prefix('assistant')
            ->name('assistant.')
            ->group(function () {
                Route::get('/lead/{lead}', [JinxAssistantController::class, 'bootstrap'])->name('bootstrap');
                Route::post('/lead/{lead}/message', [JinxAssistantController::class, 'send'])->name('send');
                Route::post('/lead/{lead}/reset', [JinxAssistantController::class, 'reset'])->name('reset');
            });
    }
}
