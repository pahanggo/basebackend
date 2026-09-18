<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as MiddlewareTrustProxies;
use Illuminate\Http\Request;

class TrustProxies extends MiddlewareTrustProxies
{
    /**
     * The trusted proxies for this application.
     *
     * The loopback, because that is where a Cloudflare Tunnel reaches us from:
     * `cloudflared` runs on this host and connects to the origin over plain
     * HTTP, so without this the application never learns that the browser's
     * request was HTTPS and generates `http://` URLs for an `https://` page.
     *
     * Cloudflare's Automatic HTTPS Rewrites hides that for links and assets in
     * the HTML, which is why it can go unnoticed — but it cannot rewrite a URL
     * inside a JSON blob or a JavaScript string, so those break as mixed
     * content while the rest of the page looks fine.
     *
     * Not `*`. Trusting every client would let anyone who can reach the origin
     * directly spoof `X-Forwarded-For`, and this application reads the client
     * IP (see `SetTokenIp`). Override with `TRUSTED_PROXIES` where the proxy is
     * not on this host.
     *
     * @var array|string|null
     */
    protected $proxies;

    public function __construct()
    {
        $this->proxies = explode(',', (string) config('app.trusted_proxies', '127.0.0.1,::1'));
    }

    /**
     * The headers that should be used to detect proxies.
     *
     * @var int
     */
    protected $headers = Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_AWS_ELB;
}
