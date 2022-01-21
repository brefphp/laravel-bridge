<?php declare(strict_types=1);

use Bref\LaravelBridge\Queue\LaravelSqsHandler;
use Illuminate\Foundation\Application;

require __DIR__ . '/vendor/autoload.php';
/** @var Application $app */
$app = require __DIR__ . '/bootstrap/app.php';

/**
 * For Lumen, use:
 * $app->make(Laravel\Lumen\Console\Kernel::class);
 * $app->boot();
 */
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

return $app->makeWith(LaravelSqsHandler::class, [
    'connection' => 'sqs', // this is the Laravel Queue connection
    'queue' => getenv('SQS_QUEUE'),
]);
