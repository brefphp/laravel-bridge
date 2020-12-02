Package to use Laravel on AWS Lambda with [Bref](https://bref.sh/).

This package provides the following benefits:

- it configures Laravel for AWS Lambda (for websites, APIs or workers)
- it provides a bridge to run Laravel Queues worker on AWS Lambda

You can read the [Bref documentation for Laravel](https://bref.sh/docs/frameworks/laravel.html) for more documentation.

In any case, it is recommended to [first learn about serverless, AWS Lambda and Bref](https://bref.sh/docs/) before using this package.

## Installation

```bash
composer require bref/laravel-bridge
```

The `Bref\LaravelBridge\BrefServiceProvider` service provider will be registered automatically.

You can now create a default `serverless.yml` at the root of your project by running:

```bash
php artisan vendor:publish --tag=serverless-config
```

The application is now ready to be deployed:

```bash
serverless deploy
```

## Laravel Queues with SQS

This package lets you process jobs from SQS queues on AWS Lambda by integrating with Laravel Queues and its job system. A deployable example is available in the [bref-laravel-sqs-demo](https://github.com/mnapoli/bref-laravel-sqs-demo) repository.

For example, given [a `ProcessPodcast` job](https://laravel.com/docs/7.x/queues#class-structure):

```php
<?php declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessPodcast implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int */
    private $podcastId;

    public function __construct(int $podcastId)
    {
        $this->podcastId = $podcastId;
    }

    public function handle(): void
    {
        // process the job
    }
}
```

We can dispatch this job to SQS [just like any Laravel job](https://laravel.com/docs/7.x/queues#dispatching-jobs):

```php
ProcessPodcast::dispatch($podcastId);
```

The job will be pushed to SQS. Now, instead of running the `php artisan queue:work` command, SQS will directly trigger our **handler** on AWS Lambda to process our job immediately.

### Setup

#### SQS

To create the SQS queue (and the permissions for your Lambda to read/write to it), you can either do that manually, or use `serverless.yml`.

Here is a complete example with `serverless.yml` that creates the queue, as well as sets up the permissions:

```yaml
provider:
    ...
    environment:
        APP_ENV: production
        QUEUE_CONNECTION: sqs
        SQS_QUEUE: !Ref AlertQueue
        # If you create the queue manually, the `SQS_QUEUE` variable can be defined like this:
        # SQS_QUEUE: https://sqs.us-east-1.amazonaws.com/your-account-id/my-queue
    iamRoleStatements:
        # Allows our code to interact with SQS
        -   Effect: Allow
            Action: [sqs:SendMessage, sqs:DeleteMessage]
            Resource: !GetAtt AlertQueue.Arn

functions:

    ...

    worker:
        handler: worker.php
        layers:
            - ${bref:layer.php-73}
        events:
            # Declares that our worker is triggered by jobs in SQS
            -   sqs:
                    arn: !GetAtt AlertQueue.Arn
                    # If you create the queue manually, the line above could be:
                    # arn: 'arn:aws:sqs:us-east-1:1234567890:my_sqs_queue'
                    # Only 1 item at a time to simplify error handling
                    batchSize: 1

resources:
    Resources:

        # The SQS queue
        AlertQueue:
            Type: AWS::SQS::Queue
            Properties:
                RedrivePolicy:
                    maxReceiveCount: 3 # jobs will be retried up to 3 times
                    # Failed jobs (after the retries) will be moved to the other queue for storage
                    deadLetterTargetArn: !GetAtt DeadLetterQueue.Arn

        # Failed jobs will go into that SQS queue to be stored, until a developer looks at these errors
        DeadLetterQueue:
            Type: AWS::SQS::Queue
            Properties:
                MessageRetentionPeriod: 1209600 # maximum retention: 14 days
```

As you can see in the `provider.environment` key, we define the `SQS_QUEUE` environment variable. This is how we configure Laravel to use that queue.

If you want to create the SQS queue manually, you will need to set that variable either via `serverless.yml` or the `.env` file.

**Watch out**: in the example above, we set the full SQS queue URL in the `SQS_QUEUE` variable. If you set only the queue name (which is also valid), you will need to set the `SQS_PREFIX` environment variable too.

#### Laravel

First, you need to configure [Laravel Queues](https://laravel.com/docs/7.x/queues) to use the SQS queue.

You can achieve this by setting the `QUEUE_CONNECTION` environment variable to `sqs`:

```dotenv
# .env
QUEUE_CONNECTION=sqs
AWS_DEFAULT_REGION=us-east-1
```

Note that on AWS Lambda, you do not need to create `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY` variables: these access keys are created automatically by Lambda and available through those variables. There is, however, one thing missing: the `AWS_SESSION_TOKEN` variable is not taken into account by Laravel by default (comment on [this issue](https://github.com/laravel/laravel/pull/5138#issuecomment-624025825) if you want this fixed). In the meantime, edit `config/queue.php` to add this line:

```diff
        'sqs' => [
            'driver' => 'sqs',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
+           'token' => env('AWS_SESSION_TOKEN'),
            'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
```

Next, create a `worker.php` file. This is the file that will handle SQS events in AWS Lambda:

```php
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
```

You may need to adjust the `connection` and `queue` options above if you customized the configuration in `config/queue.php`. If you are unsure, have a look [at the official Laravel documentation about connections and queues](https://laravel.com/docs/7.x/queues#connections-vs-queues).

That's it! Anytime a job is pushed to the SQS queue, SQS will invoke `worker.php` on AWS Lambda and our job will be executed.

#### Differences and limitations

The SQS + Lambda integration already has a retry mechanism (with a dead letter queue for failed messages). This is why those mechanisms from Laravel are not used at all. These should instead be configured on SQS (by default, jobs are retried in a loop for several days). An example on how to configure SQS is [available in the example repository](https://github.com/mnapoli/bref-laravel-sqs-demo/blob/master/serverless.yml#L55-L69).

For those familiar with Lambda, you may know that batch processing implies that any failed job will mark all the other jobs of the batch as "failed". However, Laravel manually marks successful jobs as "completed" (i.e. those are properly deleted from SQS).
