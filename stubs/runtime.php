<?php

use CacheWerk\BrefLaravelBridge\MaintenanceMode;
use CacheWerk\BrefLaravelBridge\Http\HttpHandler;
use CacheWerk\BrefLaravelBridge\Http\OctaneHandler;
use CacheWerk\BrefLaravelBridge\Queue\QueueHandler;
use CacheWerk\BrefLaravelBridge\Octane\OctaneClient;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;

require __DIR__ . '/../vendor/autoload.php';

$runtime = $_ENV['APP_RUNTIME'] ?? null;

$app = require __DIR__ . '/../bootstrap/app.php';

if ($runtime === 'queue') {
    MaintenanceMode::setUp();

    $app->make(ConsoleKernel::class)->bootstrap();
    $config = $app->make('config');

    return $app->makeWith(QueueHandler::class, [
        'connection' => $config['queue.default'],
        'queue' => $config['queue.connections.sqs.queue'],
    ]);
}

return new HttpHandler(
    $app->make(HttpKernel::class)
);
