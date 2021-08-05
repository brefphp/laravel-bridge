Package to use Laravel on AWS Lambda with [Bref](https://bref.sh/).

This package provides the following benefits:

- it configures Laravel for AWS Lambda (for websites, APIs or workers)
- it provides a bridge to run Laravel Queues worker on AWS Lambda

You can read the [Bref documentation for Laravel](https://bref.sh/docs/frameworks/laravel.html) for more documentation.

In any case, it is recommended to [first learn about serverless, AWS Lambda and Bref](https://bref.sh/docs/) before using this package.

## Installation

```bash
composer require bref/laravel-bridge --update-with-dependencies
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

## Usage

Read [the official Laravel Bridge documentation on bref.sh](https://bref.sh/docs/frameworks/laravel.html).

## Laravel Queues with SQS

This package lets you process jobs from SQS queues on AWS Lambda by integrating with Laravel Queues and its job system. A deployable example is available in the [bref/examples repository](https://github.com/brefphp/examples/tree/master/Laravel/queues).

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

To make things simpler, we will use the [Serverless Lift](https://github.com/getlift/lift) plugin to create and configure the SQS queue.

1. [Install Lift](https://github.com/getlift/lift#installation)

    ```bash
    serverless plugin install -n serverless-lift
    ```

2. Use [the Queue construct](https://github.com/getlift/lift/blob/master/docs/queue.md) in `serverless.yml`:

```yaml
provider:
    ...
    environment:
        APP_ENV: production
        QUEUE_CONNECTION: sqs
        SQS_QUEUE: ${construct:jobs.queueUrl}

functions:
    ...

constructs:
    jobs:
        type: queue
        worker:
            handler: worker.php
            layers:
                - ${bref:layer.php-74}
```

We define Laravel environment variables in `provider.environment` (this could also be done in the deployed `.env` file):

- `QUEUE_CONNECTION: sqs` enables the SQS queue connection
- `SQS_QUEUE: ${construct:jobs.queueUrl}` passes the URL of the created SQS queue

If you want to create the SQS queue manually, you will need to set these variables.

**Watch out**: in the example above, we set the full SQS queue URL in the `SQS_QUEUE` variable. If you set only the queue name (which is also valid), you will need to set the `SQS_PREFIX` environment variable too.

Note that on AWS Lambda, you do not need to create `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY` variables: these access keys are created automatically by Lambda and available through those variables. There is, however, one thing missing: the `AWS_SESSION_TOKEN` variable is not taken into account by Laravel by default (comment on [this issue](https://github.com/laravel/laravel/pull/5138#issuecomment-624025825) if you want this fixed). In the meantime, **edit `config/queue.php` to add this line**:

```diff
        'sqs' => [
            'driver' => 'sqs',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
+           'token' => env('AWS_SESSION_TOKEN'),
            'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
```

Finally, create the `worker.php` file. This is the file that will handle SQS events in AWS Lambda:

```bash
php artisan vendor:publish --tag=serverless-worker
```

That's it! Anytime a job is pushed to the SQS queue, SQS will invoke `worker.php` on AWS Lambda and our job will be executed.

#### Differences and limitations

The SQS + Lambda integration already has a retry mechanism (with a "dead letter queue" that stores failed messages). This is why those mechanisms from Laravel are not used at all.

The Lift "queue" construct automatically configures failed messages to be retried 3 times. Read [the Lift Queue documentation](https://github.com/getlift/lift/blob/master/docs/queue.md) for more details and options.

Note: for those familiar with Lambda, you may know that batch processing implies that any failed job will mark all the other jobs of the batch as "failed". However, Laravel manually marks successful jobs as "completed" (i.e. those are properly deleted from SQS).

#### Failed messages

Lift provides CLI commands to list and manage failed messages. For example:

```bash
# List failed messages
serverless jobs:failed

# Purge failed messages
serverless jobs:failed:purge

# Retry failed messages
serverless jobs:failed:retry
```

Read more [about Lift Queue commands](https://github.com/getlift/lift/blob/master/docs/queue.md#commands).
