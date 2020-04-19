[![Build Status](https://travis-ci.com/bref/bref-laravel.svg?branch=master)](https://travis-ci.com/bref/bref-laravel)
[![Latest Version](https://img.shields.io/github/release/bref/bref-laravel.svg?style=flat-square)](https://packagist.org/packages/bref/bref-laravel)
[![PrettyCI Status](https://hc4rcprbe1.execute-api.eu-west-1.amazonaws.com/dev?name=bref/bref-laravel)](https://prettyci.com/)
[![Monthly Downloads](https://img.shields.io/packagist/dm/bref/bref-laravel.svg)](https://packagist.org/packages/bref/bref-laravel/stats)

**These instructions apply to Laravel 7.**

## Installation

First, you need to configure [Laravel Queues](https://laravel.com/docs/7.x/queues) to use the SQS queue.

You can achieve this by setting the `QUEUE_CONNECTION` environment variable to `sqs`:

```dotenv
# .env
QUEUE_CONNECTION=sqs
SQS_PREFIX=https://sqs.us-east-1.amazonaws.com/your-account-id
SQS_QUEUE=my-sqs-queue
AWS_DEFAULT_REGION=us-east-1
```

Note that on AWS Lambda, we do not need to create `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY` variables: these access keys are created automatically by Lambda and available through those variables.

Now, you can start installing the Laravel bridge for Bref:

```bash
composer require bref/laravel-bridge
```

Create a `handler.php` file. This is the file that will handle SQS events in AWS Lambda:

```php
<?php declare(strict_types=1);

use Bref\LaravelBridge\Queue\LaravelSqsHandler;
use Illuminate\Foundation\Application;

require __DIR__ . '/vendor/autoload.php';
/** @var Application $app */
$app = require __DIR__ . '/bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

return $app->makeWith(LaravelSqsHandler::class, [
    'connection' => 'sqs',
    'queue' => getenv('SQS_QUEUE'),
]);
```

You may need to adjust the `connection` and `queue` options above if you customized the configuration in `config/queue.php`. If you are unsure, have a look [at the official Laravel documentation about connections and queues](https://laravel.com/docs/7.x/queues#connections-vs-queues).

We can now configure our handler in `serverless.yml`:

```yaml
functions:
    worker:
        handler: handler.php
        timeout: 20 # in seconds
        reservedConcurrency: 5 # max. 5 messages processed in parallel
        layers:
            - ${bref:layer.php-74}
        events:
            - sqs:
                arn: arn:aws:sqs:us-east-1:1234567890:my_sqs_queue
                # Only 1 item at a time to simplify error handling
                batchSize: 1
```
