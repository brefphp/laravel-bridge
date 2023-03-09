<?php

namespace Bref\LaravelBridge\Http\Middleware;

use Closure;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
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
        $requestPath = rawurldecode(ltrim($request->getPathInfo(), '/'));
        $file = public_path($requestPath);

        if (! in_array($requestPath, Config::get('bref.assets', [])) || ! file_exists($file)) {
            return $next($request);
        }

        return Response::make(file_get_contents($file), 200, [
            'Cache-Control' => 'public',
            'Content-Type' => $this->getMimeType($file),
            'Content-Length' => filesize($file),
            'ETag' => hash_file('sha1', $file),
        ]);
    }

    /**
     * Returns the cleaned mime-type of the given file.
     *
     * @param  string  $file
     * @return string
     */
    protected function getMimeType(string $file)
    {
        $mimeType = finfo_file(finfo_open(FILEINFO_MIME_TYPE), $file);
        $mimeType = strstr($mimeType, ';', true);

        return str_replace(
            ['image/vnd.microsoft.icon'],
            ['image/x-icon'],
            $mimeType
        );
    }
}
