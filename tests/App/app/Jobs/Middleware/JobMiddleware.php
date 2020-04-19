<?php declare(strict_types=1);

namespace Bref\Test\LaravelBridge\App\app\Jobs\Middleware;

class JobMiddleware
{
    public function handle($job, $next)
    {
        // This middleware lets us test that middlewares work as expected
        echo "Before job\n";

        $next($job);

        echo "After job\n";
    }
}
