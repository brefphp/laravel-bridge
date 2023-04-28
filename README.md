# Bref Laravel Bridge

Run Laravel on AWS Lambda with [Bref](https://bref.sh/).

Read the [Bref documentation for Laravel](https://bref.sh/docs/frameworks/laravel.html) to get started.

## Background

This package was originally created by [CacheWerk](https://github.com/cachewerk/) (the creators of [Relay](https://relay.so)), maintained by [Till Krüss](https://github.com/tillkruss) and [George Boot](https://github.com/georgeboot). It was published at [cachewerk/bref-laravel-bridge](https://github.com/cachewerk/bref-laravel-bridge).

For Bref 2.0, the contributors joined the Bref organization and CacheWerk's bridge was merged into this repository to create v2.0 of the bridge.

## Installation

First, be sure to familiarize yourself with Bref and its guide to [Serverless Laravel applications](https://bref.sh/docs/frameworks/laravel.html).

Next, install the package and publish the custom Bref runtime:

```
composer require bref/laravel-bridge

php artisan vendor:publish --tag=serverless-config
```

This will create the `serverless.yml` config file.

Finally, deploy your app:

```bash
serverless deploy
```

When running in AWS Lambda, the Laravel application will automatically cache its configuration when booting. You don't need to run `php artisan config:cache` before deploying.

You can deploy to different environments (aka "stages") by using the `--stage` option:

```bash
serverless deploy --stage=staging
```

Check out some more [comprehensive examples](examples/).

## Octane

If you want to run the HTTP application with Laravel Octane, you will to change the following options in the `web` function:

```yml
functions:
    web:
        handler: Bref\LaravelBridge\Http\OctaneHandler
        environment:
            BREF_LOOP_MAX: 250
        layers:
            - ${bref:layer.php-81}
        # ...
```

## Laravel Queues

If you want to run Laravel Queues, you will need to add a `queue` function to `serverless.yml`:

```yml
functions:
    queue:
        handler: Bref\LaravelBridge\Queue\QueueHandler
        timeout: 59 # in seconds
        layers:
            - ${bref:layer.php-81}
        events:
            -   sqs:
                    arn: !GetAtt Queue.Arn
                    batchSize: 1
                    maximumBatchingWindow: 60
```

## Configuration

### Serving static assets

If you want to serve some static assets from your app's `public` directory, you can use the `ServeStaticAssets` middleware.

First, publish the configuration:

```
php artisan vendor:publish --tag=bref-config
```

Then define the files you want to serve in `bref.assets`.

Lastly tell Bref to support binary responses on your `web` function:

```yml
functions:
  web:
    handler: public/index.php
    environment:
      BREF_BINARY_RESPONSES: 1
```

### Persistent database sessions

If you're using PostgreSQL 9.6 or newer, you can take advantage of persistent database sessions.

First set [`idle_in_transaction_session_timeout`](https://www.postgresql.org/docs/current/runtime-config-client.html#GUC-IDLE-IN-TRANSACTION-SESSION-TIMEOUT) either in your RDS database's parameter group, or on a specific database itself.

```sql
ALTER DATABASE SET idle_in_transaction_session_timeout = '10000' -- 10 seconds in ms
```

Lastly, set the `OCTANE_PERSIST_DATABASE_SESSIONS` environment variable.

```yml
functions:
  web:
    handler: Bref\LaravelBridge\Http\OctaneHandler
    environment:
      BREF_LOOP_MAX: 250
      OCTANE_PERSIST_DATABASE_SESSIONS: 1
```

### JSON logs

If you want all CloudWatch log entries to be JSON objects (for example because you want to ingest those logs in other systems), you can edit `config/logging.php` to set the `channels.stderr.formatter` to `Monolog\Formatter\JsonFormatter::class`.

### File storage

When running on Lambda, the filesystem is temporary and not shared between instances. If you want to use the Filesystem API, you will need to use the `s3` adapter to store files on AWS S3.

To do this, set `FILESYSTEM_DISK: s3` either in `serverless.yml` or your production `.env` file and configure the S3 bucket to use in `config/filesystems.php`.

## Usage

### Artisan Console

Just like with Bref, you may [execute console commands](https://bref.sh/docs/runtimes/console.html).

```
vendor/bin/bref cli <service>-<stage>-cli -- route:list

vendor/bin/bref cli example-staging-cli -- route:list
```

### Maintenance mode

Similar to the `php artisan down` command, you may put your app into maintenance mode. All that's required is setting the `MAINTENANCE_MODE` environment variable:

```yml
provider:
  environment:
    MAINTENANCE_MODE: ${param:maintenance, null}
```

You can then quickly put all functions into maintenance without running a full build and CloudFormation deploy:

```
serverless deploy function --function=web --update-config --param="maintenance=1"
serverless deploy function --function=cli --update-config --param="maintenance=1"
serverless deploy function --function=queue --update-config --param="maintenance=1"
```

To take your app out of maintenance mode, simply omit the parameter: 

```
serverless deploy function --function=web --update-config
serverless deploy function --function=cli --update-config
serverless deploy function --function=queue --update-config
```

One caveat with the `--update-config` flag is that it doesn't do objects in `environment` variables in the `serverless.yml`:

```yml
provider:
  environment:
    SQS_QUEUE: ${self:service}-${sls:stage}    # good
    SQS_QUEUE: !Ref QueueName                  # bad
    SQS_QUEUE:                                 # bad
      Ref: QueueName
```
