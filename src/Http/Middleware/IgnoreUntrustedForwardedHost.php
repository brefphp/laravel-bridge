<?php

namespace Bref\LaravelBridge\Http\Middleware;

use Closure;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;

/**
 * Clients can send X-Forwarded-Host and X-Forwarded-Prefix through API Gateway and Function URLs,
 * and all proxies are trusted on Lambda: without this, they could choose the domain of generated URLs.
 */
class IgnoreUntrustedForwardedHost
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
        if (Config::get('bref.trust_forwarded_host', false)) {
            return $next($request);
        }

        $request->headers->remove('X-Forwarded-Prefix');

        if (! $this->isTrusted($request)) {
            $request->headers->remove('X-Forwarded-Host');
        }

        return $next($request);
    }

    private function isTrusted(Request $request): bool
    {
        // A CDN sets X-Forwarded-Host because it calls the application on its AWS domain.
        // On any other domain, like a custom domain on API Gateway, the Host header is already the right one.
        if (! preg_match('/\.(amazonaws\.com|on\.aws)$/i', (string) $request->headers->get('Host'))) {
            return false;
        }

        $applicationHost = parse_url((string) Config::get('app.url'), PHP_URL_HOST);

        // Without APP_URL we can't tell a CDN from a client calling the AWS domain directly
        if (! $applicationHost || $applicationHost === 'localhost') {
            return true;
        }

        return preg_match('/^([a-z0-9-]+\.)*' . preg_quote($applicationHost, '/') . '$/iD', (string) $request->headers->get('X-Forwarded-Host')) === 1;
    }
}
