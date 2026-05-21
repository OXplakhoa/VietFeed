<?php

namespace App\Providers;

use Illuminate\Pagination\Paginator;
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
    }
}
