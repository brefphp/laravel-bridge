# Changelog
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]
### Added
* `bref.stack_name` config value: the name of the CloudFormation stack (`BREF_STACK_NAME` environment variable, set by Bref when deploying with `serverless.yml`). It lets applications forward queue names to Lift queues: [documentation](https://bref.sh/docs/laravel/queues#multiple-queues).

### Changed
* Allow `bref/monolog-bridge` 2.0: on Lambda, log lines start with the request ID of the invocation, so that all the logs of a request can be found with it: [documentation](https://bref.sh/docs/environment/logs#logs-of-a-single-request). To keep the previous log format, require `bref/monolog-bridge: ^1.0` in your application.

### Fixed
* When a failed job could not be stored (for example with the default `database-uuids` failed job driver in an application without a database), the error replaced the job's own exception, which was never logged or reported. Both errors are now reported.
* Failed jobs are now stored by the Lambda queue handler, like `php artisan queue:work` does, instead of by a listener registered in every process. With `php artisan queue:work` (e.g. in local development), failed jobs were stored twice: with the default `database-uuids` driver, the second insert failed and its error replaced the job's own exception. This also means that failed jobs are stored on Lambda even when `bref.log_jobs` is disabled, and that failed `sync` jobs are no longer stored, like in a standard Laravel application.

## [v3.1.0]
### Added
* Simple large file uploads via presigned S3 uploads: [documentation](https://bref.sh/docs/laravel/file-storage#large-files).
  This registers `POST /signed-upload-url` by default, protected by authentication and the `uploadFiles` gate. Set `bref.uploads.route` to `null` to disable the route.

## [v3.0.0]
* Support [Bref v3](https://bref.sh/news/03-bref-3.0)
* Improve the default `serverless.yml` config

### Breaking Changes
* Drop support for PHP 8.1, by @mnapoli
* The JSON CloudWatch formatter is now enabled by default, by @mnapoli in https://github.com/brefphp/laravel-bridge/pull/193

## [v2.1.0] - 2023-03-20
### Added
* Allow running Tinker commands on Lambda by @mnapoli in https://github.com/brefphp/laravel-bridge/pull/104

### Changed
* Fix default branch in CI by @szepeviktor in https://github.com/brefphp/laravel-bridge/pull/97
* Don't unset AWS key and secrets by @georgeboot in https://github.com/brefphp/laravel-bridge/pull/98
* Fix the creation of `serverless.yml` to the correct directory by @mnapoli in https://github.com/brefphp/laravel-bridge/pull/99
* Fix service provider running order by @georgeboot in https://github.com/brefphp/laravel-bridge/pull/102
* Improve the default `serverless.yml` config by @mnapoli in https://github.com/brefphp/laravel-bridge/pull/100

## [v2.0.0]
### Breaking Changes
- Logs are now written in plain text by default instead of JSON. To enable JSON logs, set `channels.stderr.formatter` to `Monolog\Formatter\JsonFormatter::class` in `config/logging.php`.
- The automatic population of environment variables via `APP_SSM_PREFIX` and `APP_SSM_PARAMETERS` has been removed. The native Bref 2.0 feature to load SSM parameters into environment variables can be used instead ([#36](https://github.com/cachewerk/bref-laravel-bridge/pull/36))
- If you use Octane, remove the `bref/runtime.php` file, remove the `APP_RUNTIME` environment variable (in `serverless.yml`) and set your Octane function handler to: `handler: CacheWerk\BrefLaravelBridge\Http\OctaneHandler`.
- If you use Laravel Queues, remove the `bref/runtime.php` file, remove the `APP_RUNTIME` environment variable (in `serverless.yml`) and set your Octane function handler to: `handler: CacheWerk\BrefLaravelBridge\Queue\QueueHandler`.

## [v0.3.0] - 2022-11-15
### Changed
- Use Laravel-native queue handler ([#13](https://github.com/cachewerk/bref-laravel-bridge/pull/13))

## [v0.2.0] - 2022-11-07
### Added
- Added maintenance mode support ([#7](https://github.com/cachewerk/bref-laravel-bridge/pull/7))
- Support persistent PostgreSQL sessions with Octane ([#9](https://github.com/cachewerk/bref-laravel-bridge/pull/9))
- Parse `Authorization: Basic` header into `PHP_AUTH_*` variables ([#10](https://github.com/cachewerk/bref-laravel-bridge/pull/10))
- Prepare Octane responses without `Content-Type` ([08ab941](08ab941ab734d636697847b036cd9ed5e31a30ad))

### Changed 
- Made `ServeStaticAssets` configurable ([19fb1ac](19fb1ac21fd7245a8bd529eb6325cea2308ffbf2))
- Made shared `X-Request-ID` log context configurable ([bfbc249](bfbc2498d3b418f149aba3d3fe795073dfcb7b48))
- Log SQS job events ([#11](https://github.com/cachewerk/bref-laravel-bridge/pull/11))
- Collapse `Secrets` log message into single line ([#11](https://github.com/cachewerk/bref-laravel-bridge/pull/11))

## [v0.1.0] - 2022-05-18
### Added
- Initial release

[Unreleased]: https://github.com/cachewerk/bref-laravel-bridge/compare/v0.3.0...HEAD
[v0.3.0]: https://github.com/cachewerk/bref-laravel-bridge/compare/v0.2.0...v0.3.0
[v0.2.0]: https://github.com/cachewerk/bref-laravel-bridge/compare/v0.1.0...v0.2.0
[v0.1.0]: https://github.com/cachewerk/bref-laravel-bridge/releases/tag/v0.1.0
