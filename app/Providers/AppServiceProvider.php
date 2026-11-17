<?php

namespace App\Providers;

use App\Billing\StripeClient;
use App\Billing\StripeSdkClient;
use App\Interaction\InteractionReplaySource;
use App\Interaction\SyntheticInteractionReplaySource;
use App\Services\TickerService;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(StripeClient::class, StripeSdkClient::class);
        $this->app->bind(InteractionReplaySource::class, SyntheticInteractionReplaySource::class);
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
