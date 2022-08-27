# Changelog
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]
### Added
- Added maintenance mode support ([#7](https://github.com/cachewerk/bref-laravel-bridge/pull/7))
- Support persistent PostgresSQL sessions with Octane ([#9](https://github.com/cachewerk/bref-laravel-bridge/pull/9))
- Parse `Authorization: Basic` header into `PHP_AUTH_*` variables ([#9](https://github.com/cachewerk/bref-laravel-bridge/pull/10))

### Changed 
- Made `ServeStaticAssets` configurable ([19fb1ac](19fb1ac21fd7245a8bd529eb6325cea2308ffbf2))
- Made shared `X-Request-ID` log context configurable ([bfbc249](bfbc2498d3b418f149aba3d3fe795073dfcb7b48))

## [v0.1.0] - 2022-05-18
### Added
- Initial release

[Unreleased]: https://github.com/cachewerk/bref-laravel-bridge/compare/v0.1.0...HEAD
[v0.1.0]: https://github.com/cachewerk/bref-laravel-bridge/releases/tag/v0.1.0
