# Bref Laravel Bridge

An advanced Laravel integration for Bref, including Octane support.

This project is largely based on code from [PHP Runtimes](https://github.com/php-runtime/runtime), [Laravel Vapor](https://github.com/laravel/vapor-core) and [Bref's Laravel Bridge](https://github.com/brefphp/laravel-bridge).

## Background

Why does this exist and why not just use [Laravel Vapor](https://vapor.laravel.com)? Vapor is fantastic, easy to use and the better choice for situations, its $399/year pay for itself not having to maintain your own infrastructure.

For [Relay](https://relaycache.com)'s API however we needed something that 1) **is open source** _(Vapor's API is a black box)_, 2) **is secure** _(Vapor has admin access to databases and environment variables)_ and 3) doesn't leave us at the **mercy of a support team** _(Vapor has no enterprise support)_. We also didn't want to be forced to use CloudFront on top of Cloudflare, but that's just nerdy preference.

We needed an open source solution that gives us more fine-grained control and is secure.

[Bref](https://bref.sh) + [Serverless Framework](https://www.serverless.com/) is exactly that, however Bref's Laravel integration is rather basic, it easily exposes SSM secrets and it doesn't support Laravel Octane.

So we built this.

## Installation

First, be sure to make yourself familiar with Bref and [its Laravel bridge](https://bref.sh/docs/frameworks/laravel.html).

Next, install the package and publish the custom Bref runtime:

```
composer require cachewerk/bref-laravel-bridge

php artisan vendor:publish --tag=bref-runtime
```

By default the runtime is published to `php/runtime.php` where Bref's PHP configuration resides, feel free to move it.

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
      APP_RUNTIME: octane       # omit to not use Octane
      BREF_LOOP_MAX: 250        # omit to not use Octane
      BREF_BINARY_RESPONSES: 1  # optional
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

## Configuration

### Parameter Store secrets

To avoid setting secrets as environment variables on your Lambda functions, you can inject them directly into the Lambda runtime:

```yml
provider:
  environment:
    APP_SSM_PREFIX: /example-${sls:stage}/
    APP_SSM_PARAMETERS: "APP_KEY, DATABASE_URL"
```

### Log context

If you want to add the Lambda request UUID to your shared log context, set it when binding the request instance.

```php
class AppServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->rebinding('request', function ($app, $request) {
            $request->hasHeader('X-Request-ID') &&
                $app->make('log')->shareContext([
                    'requestId' => $request->header('X-Request-ID'),
                ]);
        });
    }
}
```
