<?php

namespace App\Providers;

use App\Billing\StripeClient;
use App\Billing\StripeSdkClient;
use App\Content\CategoryList;
use App\Interaction\InteractionReplaySource;
use App\Interaction\SyntheticInteractionReplaySource;
use App\Moderation\SanctionSnapshot;
use App\Services\TickerService;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Pagination\Paginator;
use Illuminate\Routing\Events\Routing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(StripeClient::class, StripeSdkClient::class);
        $this->app->bind(InteractionReplaySource::class, SyntheticInteractionReplaySource::class);
        $this->app->singleton(SanctionSnapshot::class);
    }

    public function boot(): void
    {
        // Tailwind is the framework default; switch to Bootstrap 5 because
        // our UI is Bootstrap, and the Tailwind view would render
        // unstyled (full-size) SVG arrows.
        Paginator::useBootstrapFive();

        // Gate 9b: sanction checks read one snapshot per HTTP request (see SanctionSnapshot).
        Event::listen(Routing::class, fn () => $this->app->make(SanctionSnapshot::class)->begin());
        Event::listen(RequestHandled::class, fn () => $this->app->make(SanctionSnapshot::class)->end());

        View::composer('layouts.app', function ($view) {
            try {
                $ticker = app(TickerService::class)->snapshot();
            } catch (\Exception $e) {
                $ticker = ['usd' => null, 'sjc' => null];
            }
            $view->with('ticker', $ticker);
            $view->with('navigationCategories', app(CategoryList::class)->active());
        });
    }
}
