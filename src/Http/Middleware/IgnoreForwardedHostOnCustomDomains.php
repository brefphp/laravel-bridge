<?php

namespace Bref\LaravelBridge\Http\Middleware;

use Closure;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;

/**
 * Clients can send X-Forwarded-Host and X-Forwarded-Prefix through API Gateway, and all proxies are
 * trusted on Lambda: without this, they could choose the domain of the URLs generated on a custom domain.
 */
class IgnoreForwardedHostOnCustomDomains
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // A CDN sets these headers because it calls the application on its AWS domain.
        // On any other domain, the Host header is already the right one.
        $isAwsDomain = preg_match('/\.(amazonaws\.com|on\.aws)$/i', (string) $request->headers->get('Host')) === 1;

        if (! $isAwsDomain && ! Config::get('bref.trust_forwarded_host', false)) {
            $request->headers->remove('X-Forwarded-Host');
            $request->headers->remove('X-Forwarded-Prefix');
        }

        return $next($request);
    }
}
