<?php

namespace App\Providers;

use App\Authorization\Permissions;
use App\Billing\StripeClient;
use App\Billing\StripeSdkClient;
use App\Console\DestructiveCommandGuard;
use App\Content\CategoryList;
use App\Interaction\InteractionReplaySource;
use App\Interaction\SyntheticInteractionReplaySource;
use App\Moderation\SanctionSnapshot;
use App\Services\TickerService;
use App\StoryLens\Brief\BriefModel;
use App\StoryLens\Brief\Models\DisabledBriefModel;
use App\StoryLens\Brief\Models\FakeBriefModel;
use App\StoryLens\Brief\Models\GeminiBriefModel;
use App\StoryLens\Brief\Models\OpenAiBriefModel;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Routing\Events\Routing;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Gate 9.5 slice 5: the SQL runtime is gone. Laravel merges its own default `database` connections
        // (sqlite, mysql, ...), cache store and queue connection into ours, so drop them: MongoDB is the only
        // database connection and nothing can fall back to a SQL driver.
        $config = $this->app['config'];
        $config->set('database.connections', ['mongodb' => $config->get('database.connections.mongodb')]);
        $config->set('cache.stores', Arr::except($config->get('cache.stores', []), ['database']));
        $config->set('queue.connections', Arr::except($config->get('queue.connections', []), ['database']));

        $this->app->bind(StripeClient::class, StripeSdkClient::class);
        $this->app->bind(InteractionReplaySource::class, SyntheticInteractionReplaySource::class);
        $this->app->singleton(SanctionSnapshot::class);
        $this->app->bind(BriefModel::class, function (): BriefModel {
            $timeout = (int) config('ai.timeout_seconds');
            $connectTimeout = (int) config('ai.connect_timeout_seconds');

            return match (config('ai.provider')) {
                'gemini' => new GeminiBriefModel(
                    (string) config('ai.gemini.key'),
                    (string) config('ai.gemini.model'),
                    $timeout,
                    $connectTimeout,
                    (string) config('ai.gemini.thinking_level'),
                ),
                'openai' => new OpenAiBriefModel((string) config('ai.openai.base_url'), (string) config('ai.openai.key'), (string) config('ai.openai.model'), $timeout, $connectTimeout),
                'fake' => new FakeBriefModel,
                default => new DisabledBriefModel,
            };
        });

        $this->app->afterResolving(RateLimiter::class, function (RateLimiter $limiter): void {
            $limiter->for('interaction-toggles', fn (Request $request) => Limit::perMinute(30)
                ->by((string) $request->user()->getAuthIdentifier())
                ->response(fn (Request $request, array $headers) => response()->json([
                    'message' => 'Bạn thao tác quá nhanh. Vui lòng thử lại sau.',
                ], 429, $headers)));
        });
    }

    public function boot(): void
    {
        // Gate 9.5 slice 5 (REVIEW-1 MAJOR-1): refuse database-dropping commands outside `testing`.
        DestructiveCommandGuard::apply($this->app);
        Permissions::register();

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
