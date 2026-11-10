<?php

namespace Bref\LaravelBridge\Http\Middleware;

use Bref\LaravelBridge\BrefServiceProvider;
use Closure;

use Illuminate\Http\Request;

/**
 * Clients can send X-Forwarded-Host and X-Forwarded-Prefix through API Gateway, and Bref trusts all proxies
 * by default: without this, they could choose the domain of the URLs generated on a custom domain.
 *
 * Runs after the TrustProxies middleware, to know which proxies it trusted.
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
        // The CloudFront distributions created by Lift set X-Forwarded-Host,
        // because they call the application on its AWS domain.
        // On any other domain, the Host header is already the right one.
        $isAwsDomain = preg_match('/\.(amazonaws\.com|on\.aws)$/iD', (string) $request->headers->get('Host')) === 1;

        // Applications that configure their trusted proxies decide which headers they trust
        $usesDefaultTrustedProxies = Request::getTrustedProxies() === BrefServiceProvider::TRUSTED_PROXIES;

        if (! $isAwsDomain && $usesDefaultTrustedProxies) {
            $request->headers->remove('X-Forwarded-Host');
            $request->headers->remove('X-Forwarded-Prefix');
        }

        return $next($request);
    }
}
