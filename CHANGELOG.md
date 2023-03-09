# Changelog
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [v2.0.0]
### Breaking Changes
- Logs are now written in plain text by default instead of JSON. To enable JSON logs, set `channels.stderr.formatter` to `Monolog\Formatter\JsonFormatter::class` in `config/logging.php`.
- The automatic population of environment variables via `APP_SSM_PREFIX` and `APP_SSM_PARAMETERS` has been removed. The native Bref 2.0 feature to load SSM parameters into environment variables can be used instead ([#36](https://github.com/cachewerk/bref-laravel-bridge/pull/36))
- If you use Octane, remove the `bref/runtime.php` file, remove the `APP_RUNTIME` environment variable (in `serverless.yml`) and set your Octane function handler to: `handler: CacheWerk\BrefLaravelBridge\Http\OctaneHandler`.
- If you use Laravel Queues, remove the `bref/runtime.php` file, remove the `APP_RUNTIME` environment variable (in `serverless.yml`) and set your Octane function handler to: `handler: CacheWerk\BrefLaravelBridge\Queue\QueueHandler`.

## [Unreleased]
## [v0.3.0] - 2022-11-15
### Changed
- Use Laravel-native queue handler ([#13](https://github.com/cachewerk/bref-laravel-bridge/pull/13))

## [v0.2.0] - 2022-11-07
### Added
- Added maintenance mode support ([#7](https://github.com/cachewerk/bref-laravel-bridge/pull/7))
- Support persistent PostgresSQL sessions with Octane ([#9](https://github.com/cachewerk/bref-laravel-bridge/pull/9))
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
