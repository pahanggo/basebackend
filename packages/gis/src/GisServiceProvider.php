<?php

namespace Gis;

use Gis\Console\SweepCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class GisServiceProvider extends ServiceProvider
{
    /**
     * Register package configuration and its database connection.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/gis.php', 'gis');
        $this->registerDatabaseConnection();
    }

    /**
     * Boot views, migrations, routes, the sweep schedule and console commands.
     */
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'gis');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->registerRoutes();
        $this->registerSchedule();

        if ($this->app->runningInConsole()) {
            $this->commands([SweepCommand::class]);
        }
    }

    /**
     * The package owns its connection definition so the application's
     * `config/database.php` needs no entry. A deployment that defines one
     * under the same name wins.
     */
    protected function registerDatabaseConnection(): void
    {
        $name = config('gis.connection');

        if (config("database.connections.{$name}") !== null) {
            return;
        }

        config(["database.connections.{$name}" => require __DIR__.'/../config/database.php']);
    }

    /**
     * The editor page and the API share a guard: the Backpack admin middleware
     * over the web session, plus the module permission. The API is same-origin
     * and carries the session cookie and CSRF token, not a bearer token.
     */
    protected function registerRoutes(): void
    {
        $middleware = array_merge(
            (array) config('backpack.base.web_middleware', 'web'),
            (array) config('backpack.base.middleware_key', 'admin'),
            ['can:'.config('gis.route.permission')],
        );

        Route::group([
            'prefix' => trim(config('backpack.base.route_prefix', 'admin').'/'.config('gis.route.web_prefix'), '/'),
            'middleware' => $middleware,
            'as' => 'gis.',
        ], fn () => $this->loadRoutesFrom(__DIR__.'/../routes/web.php'));

        Route::group([
            'prefix' => config('gis.route.api_prefix'),
            'middleware' => $middleware,
            'as' => 'gis.api.',
        ], fn () => $this->loadRoutesFrom(__DIR__.'/../routes/api.php'));
    }

    /**
     * Registered here rather than in the application's console kernel, so the
     * package carries its own housekeeping. It still needs a `schedule:run`
     * cron entry on the deployment — see the package README.
     */
    protected function registerSchedule(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('gis:sweep')->daily();
        });
    }
}
