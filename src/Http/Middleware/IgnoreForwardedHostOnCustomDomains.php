<?php

namespace Bref\LaravelBridge\Http\Middleware;

use Bref\LaravelBridge\BrefServiceProvider;
use Closure;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use ReflectionProperty;

/**
 * Clients can send X-Forwarded-Host and X-Forwarded-Prefix through API Gateway, and Bref trusts all proxies
 * by default: without this, they could choose the domain of the URLs generated on a custom domain.
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
        $isAwsDomain = preg_match('/\.(amazonaws\.com|on\.aws)$/i', (string) $request->headers->get('Host')) === 1;

        if (! $isAwsDomain && $this->usesDefaultTrustedProxies()) {
            $request->headers->remove('X-Forwarded-Host');
            $request->headers->remove('X-Forwarded-Prefix');
        }

        return $next($request);
    }

    /**
     * Whether the application left Bref's default, instead of configuring its trusted proxies itself
     */
    private function usesDefaultTrustedProxies(): bool
    {
        if (Config::get('trustedproxy.proxies') !== BrefServiceProvider::TRUSTED_PROXIES) {
            return false;
        }

        // Set by TrustProxies::at() and TrustProxies::withHeaders(), which don't exist before Laravel 11
        foreach (['alwaysTrustProxies', 'alwaysTrustHeaders'] as $property) {
            if (property_exists(TrustProxies::class, $property) && (new ReflectionProperty(TrustProxies::class, $property))->getValue() !== null) {
                return false;
            }
        }

        return true;
    }
}
