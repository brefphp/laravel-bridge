<?php

namespace CacheWerk\BrefLaravelBridge\Http\Middleware;

use Closure;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;

class ServeStaticAssets
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
        $requestUri = $request->getRequestUri();

        if (str_starts_with($requestUri, '/favicon.ico')) {
            return $this->favicon();
        }

        if (str_starts_with($requestUri, '/robots.txt')) {
            return $this->robotstxt();
        }

        return $next($request);
    }

    /**
     * Return a `favicon.ico` response.
     *
     * @return \Illuminate\Http\Response
     */
    protected function favicon()
    {
        $file = public_path('favicon.ico');

        return Response::make(file_get_contents($file), 200, [
            'Content-Type' => 'image/x-icon',
            'Cache-Control' => 'public, max-age=86400',
            'ETag' => hash_file('sha1', $file),
        ]);
    }

    /**
     * Return a `robots.txt` response.
     *
     * @return \Illuminate\Http\Response
     */
    protected function robotstxt()
    {
        $file = public_path('robots.txt');

        return Response::make(file_get_contents($file), 200, [
            'Content-Type' => 'text/plain',
            'Cache-Control' => 'public, max-age=86400',
            'ETag' => hash_file('sha1', $file),
        ]);
    }
}
