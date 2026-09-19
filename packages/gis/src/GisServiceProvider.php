<?php

namespace Gis;

use Gis\Console\SweepCommand;
use Illuminate\Console\Scheduling\Schedule;
use Gis\Models\Map;
use Gis\Policies\MapPolicy;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Gate;
use Gis\Geometry\GeometryService;
use Gis\Geometry\GeosGeometryService;
use Gis\Geometry\GeosOp;
use Gis\Geometry\UnavailableGeometryService;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\ServiceProvider;

class GisServiceProvider extends ServiceProvider
{
    /**
     * Register package configuration and its database connection.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/gis.php', 'gis');
        $this->registerDatabaseConnections();
        $this->registerGeometryService();
    }

    /**
     * Boot views, migrations, routes, the sweep schedule and console commands.
     */
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'gis');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Gate::policy(Map::class, MapPolicy::class);

        $this->registerRateLimits();
        $this->registerRoutes();
        $this->registerBroadcastChannels();
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
    protected function registerDatabaseConnections(): void
    {
        $definitions = require __DIR__.'/../config/database.php';
        $name = config('gis.connection');

        if ($name !== null && config("database.connections.{$name}") === null) {
            config(["database.connections.{$name}" => $definitions['gis']]);
        }
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
     * Constructive geometry, bound to whichever implementation can actually
     * run here.
     *
     * **A resolver closure, never a constructor injection.** Octane boots the
     * application once and reuses it, so a singleton holding the container,
     * the request or the config repository holds the FIRST request's copy for
     * the life of the worker. The closure re-reads on every resolution
     * instead, which is also what lets the binary's absence be noticed without
     * a restart.
     *
     * `scoped` rather than `singleton` for the same reason: it is discarded
     * between requests.
     */
    protected function registerGeometryService(): void
    {
        $this->app->scoped(GeometryService::class, function () {
            $geos = new GeosOp;

            return $geos->available()
                ? new GeosGeometryService($geos)
                : new UnavailableGeometryService;
        });
    }

    /**
     * Write limits, from config rather than a literal in the route file.
     *
     * Reads are deliberately not limited here: the editor issues one per layer
     * per settled view and the padding in the client's feed is what bounds
     * them, so a limit low enough to matter would break ordinary panning.
     */
    protected function registerRateLimits(): void
    {
        RateLimiter::for('gis-writes', fn ($request) => Limit::perMinute(
            (int) config('gis.rate_limits.writes_per_minute'),
        )->by($request->user()?->getAuthIdentifier() ?: $request->ip()));

        // Uploads are hourly rather than per minute: one overlay is a
        // deliberate act a user performs a handful of times, and each one costs
        // a decode and a re-encode of up to 20 MB.
        RateLimiter::for('gis-images', fn ($request) => Limit::perHour(
            (int) config('gis.rate_limits.image_uploads_per_hour'),
        )->by($request->user()?->getAuthIdentifier() ?: $request->ip()));
    }

    /**
     * Channel authorization for `private-map.{id}`.
     *
     * Loaded whether or not a websocket server is running: with the `log`
     * broadcast driver the channel is inert, and standing it up later is a
     * driver change rather than a code change (specification section 5).
     */
    protected function registerBroadcastChannels(): void
    {
        if (! $this->app->bound(\Illuminate\Contracts\Broadcasting\Factory::class)) {
            return;
        }

        Broadcast::routes();

        require __DIR__.'/../routes/channels.php';
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
