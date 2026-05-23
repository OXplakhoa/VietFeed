<?php

namespace App\Providers;

use App\Services\TickerService;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Tailwind is the framework default; switch to Bootstrap 5 because
        // our UI is Bootstrap, and the Tailwind view would render
        // unstyled (full-size) SVG arrows.
        Paginator::useBootstrapFive();

        View::composer('layouts.app', function ($view) {
            try {
                $ticker = app(TickerService::class)->snapshot();
            } catch (\Exception $e) {
                $ticker = ['usd' => null, 'sjc' => null];
            }
            $view->with('ticker', $ticker);
        });
    }
}
