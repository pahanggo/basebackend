<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the locale the user picked with the header language switcher.
 *
 * Runs on every web request, after StartSession. The locale is always set
 * explicitly — never left to whatever the container already held — because
 * under Octane the application instance is reused between requests and a
 * conditional setLocale() would leak one user's language into the next.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale($this->resolveLocale($request));

        return $next($request);
    }

    /**
     * The session value if it is still an offered locale, otherwise the
     * configured default. A locale dropped from config('app.available_locales')
     * therefore stops applying on the next request without needing a session flush.
     */
    protected function resolveLocale(Request $request): string
    {
        $selected = $request->hasSession() ? $request->session()->get('locale') : null;

        if (is_string($selected) && array_key_exists($selected, config('app.available_locales', []))) {
            return $selected;
        }

        return config('app.locale');
    }
}
