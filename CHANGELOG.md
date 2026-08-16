# Changelog

All notable changes to `laravel-appsync-broadcaster` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.1.0] - 2026-08-16

Adds Laravel 13 support. Laravel 10, 11, 12 and 13 are all supported from this
single release; the full matrix is verified in CI.

### Added
- **Laravel 13 support** (`illuminate/* ^13.0`)
- GitHub Actions test matrix covering every supported Laravel/PHP combination
  (Laravel 10–13 on PHP 8.1–8.4)
- `BroadcasterResolutionTest`, which resolves the broadcaster through
  `BroadcastManager` the way Laravel itself does. This covers the driver-closure
  path that the fix below applies to, on every supported version
- Compatibility table in the README, including a note on installing on the
  security-EOL Laravel 10 / 11 branches

### Fixed
- **Laravel 13 compatibility.** Laravel 13 rebinds driver closures registered
  through a manager's `extend()` to the manager instance, so `$this` inside the
  broadcaster closure was no longer the service provider. `$this->buildTransport()`
  resolved against `BroadcastManager` and fatalled with
  `Call to undefined method`, meaning no AppSync broadcaster could be built at all
  on Laravel 13. Construction moved to the static
  `AppSyncBroadcastServiceProvider::makeBroadcaster()` / `makeTransport()`, which
  do not depend on how the closure is bound. Behaviour on Laravel 10–12 is
  unchanged

### Changed
- `phpunit.xml` migrated to the PHPUnit 10+ schema — `<source>` replaces
  `<coverage><include>`, and the removed `verbose` attribute is dropped — so the
  suite runs warning-free on PHPUnit 10 through 13
- Dev requirements widened to PHPUnit `^10.5|^11.0|^12.0|^13.0` and Orchestra
  Testbench `^8.0|^9.0|^10.0|^11.0`
- `minimum-stability` set to `stable`

### Improved
- Configuration publishing now only merges package config when not published
- Updated README to clarify automatic configuration registration
- Enhanced configuration file documentation
- Comprehensive frontend integration documentation
- JavaScript/Node.js WebSocket connection examples
- Browser-based subscription class example
- Detailed channel name format documentation
- Authentication flow explanation

## [1.0.0] - 2025-08-28

### Added
- Initial release of Laravel AppSync Broadcaster
- Support for AWS AppSync real-time broadcasting
- Integration with Laravel Broadcasting system
- Support for public, private, and presence channels
- Cognito authentication for private channels
- Automatic retry mechanism with exponential backoff
- Comprehensive error handling and logging
- Laravel service provider with auto-discovery
- Configuration validation
- Unit tests with Orchestra Testbench
- Complete documentation and examples

### Features
- Real-time broadcasting via AWS AppSync GraphQL subscriptions
- Channel authentication and authorization
- Configurable retry logic
- Environment-based configuration
- Laravel Broadcasting compatibility
- Presence channel support with user information
- Comprehensive error handling

### Requirements
- PHP ^8.0
- Laravel ^9.0|^10.0|^11.0
- AWS AppSync API
- AWS Cognito User Pool
