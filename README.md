# Bref Laravel Bridge

An advanced Laravel integration for Bref, including Octane support.

This project is largely based on code from [PHP Runtimes](https://github.com/php-runtime/runtime), [Laravel Vapor](https://github.com/laravel/vapor-core) and [Bref's Laravel Bridge](https://github.com/brefphp/laravel-bridge).

## Background

Why does this exist and why not just use [Laravel Vapor](https://vapor.laravel.com)? Vapor is fantastic, easy to use and the better choice for situations, its $399/year pay for itself not having to maintain your own infrastructure.

For [Relay](https://relay.so)'s API however we needed something that 1) **is open source** _(Vapor's API is a black box)_, 2) **is secure** _(Vapor has admin access to databases and environment variables)_ and 3) doesn't leave us at the **mercy of a support team** _(Vapor has no enterprise support)_. We also didn't want to be forced to use CloudFront on top of Cloudflare, but that's just nerdy preference.

We needed an open source solution that gives us more fine-grained control and is secure.

[Bref](https://bref.sh) + [Serverless Framework](https://www.serverless.com/) is exactly that, however Bref's Laravel integration is rather basic, it easily exposes SSM secrets and it doesn't support Laravel Octane.

So we built this.

## Installation

First, be sure to familiarize yourself with Bref and its guide to [Serverless Laravel applications](https://bref.sh/docs/frameworks/laravel.html).

Next, install the package and publish the custom Bref runtime:

```
composer require cachewerk/bref-laravel-bridge

php artisan vendor:publish --tag=bref-runtime
```

By default the runtime is published to `php/` where Bref's PHP configuration resides, but it can be move anywhere.

Next, we need to set up in the `AWS_ACCOUNT_ID` environment variable in your `serverless.yml`:

```yml
provider:
  environment:
    AWS_ACCOUNT_ID: ${aws:accountId}
```

Then set up your functions:

```yml
functions:
  web:
    handler: php/runtime.php
    environment:
      APP_RUNTIME: octane
      BREF_LOOP_MAX: 250
    layers:
      - ${bref:layer.php-81}
    events:
      - httpApi: '*'

  queue:
    handler: php/runtime.php
    timeout: 59
    environment:
      APP_RUNTIME: queue
    layers:
      - ${bref:layer.php-81}
    events:
      - sqs:
          arn: !GetAtt Queue.Arn
          batchSize: 1
          maximumBatchingWindow: 60

  cli:
    handler: php/runtime.php
    timeout: 720
    environment:
      APP_RUNTIME: cli
    layers:
      - ${bref:layer.php-81}
      - ${bref:layer.console}
    events:
      - schedule:
          rate: rate(1 minute)
          input: '"schedule:run"'
```

If you don't want to use Octane, simply remove `APP_RUNTIME` and `BREF_LOOP_MAX` from the `web` function.

To avoid setting secrets as environment variables on your Lambda functions, you can inject them directly into the Lambda runtime:

```yml
provider:
  environment:
    APP_SSM_PREFIX: /${self:service}-${sls:stage}/
    APP_SSM_PARAMETERS: "APP_KEY, DATABASE_URL"
```

This will inject `APP_KEY` and `DATABASE_URL` using your service name and stage, for example from `/myapp-staging/APP_KEY`.

Finally, deploy your app:

```
sls deploy --stage=staging
```

Check out some more [comprehensive examples](examples/).

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
    handler: php/runtime.php
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
    handler: php/runtime.php
    environment:
      APP_RUNTIME: octane
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

One caveat with the `--update-config` flag is that it doesn't objects in `environment` variables in the `serverless.yml`:

```yml
provider:
  environment:
    SQS_QUEUE: ${self:service}-${sls:stage}    # good
    SQS_QUEUE: !Ref QueueName                  # bad
    SQS_QUEUE:                                 # bad
      Ref: QueueName
```
