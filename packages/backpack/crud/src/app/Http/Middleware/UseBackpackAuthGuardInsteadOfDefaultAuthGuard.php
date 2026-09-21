<?php

namespace Backpack\CRUD\app\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class UseBackpackAuthGuardInsteadOfDefaultAuthGuard
{
    /**
     * Handle an incoming request.
     *
     * @param  Request  $request
     * @param  string|null  $guard
     * @return mixed
     */
    public function handle($request, Closure $next, $guard = null)
    {
        app('auth')->setDefaultDriver(config('backpack.base.guard'));

        return $next($request);
    }
}
