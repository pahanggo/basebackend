<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;
use Symfony\Component\HttpFoundation\Cookie;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array
     */
    protected $except = [
        '/api/*'
    ];

    /**
     * Create a new "XSRF-TOKEN" cookie that contains the CSRF token.
     *
     * Overridden to mark the XSRF-TOKEN cookie as HttpOnly (Laravel's default
     * is false). This app hands the CSRF token to JavaScript through the
     * <meta name="csrf-token"> tag — echoed back via the X-CSRF-TOKEN header —
     * and through @csrf form fields, so no client-side script needs to read
     * the XSRF-TOKEN cookie itself.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  array  $config
     * @return \Symfony\Component\HttpFoundation\Cookie
     */
    protected function newCookie($request, $config)
    {
        return new Cookie(
            strtoupper(config('app.name')) . '-XSRF-TOKEN',
            $request->session()->token(),
            $this->availableAt(60 * $config['lifetime']),
            $config['path'],
            $config['domain'],
            $config['secure'],
            $config['http_only'],
            false,
            $config['same_site'] ?? null,
            $config['partitioned'] ?? false
        );
    }
}
