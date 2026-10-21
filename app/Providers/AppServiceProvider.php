<?php

namespace App\Providers;

use App\Services\LeadUpserter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /**
         * One upserter per command or request, so the importer and whatever
         * reports on it see the same counters. Two instances meant the import
         * summary printed zeroes while 3,486 rows were being written.
         */
        $this->app->scoped(LeadUpserter::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
