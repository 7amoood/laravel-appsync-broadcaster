# Changelog

All notable changes to `laravel-appsync-broadcaster` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.2.0] - 2026-11-01

`redis` mode now queues events on a Redis **list** (`RPUSH` / `BLPOP`) instead
of Pub/Sub (`PUBLISH` / `SUBSCRIBE`). Pub/Sub keeps nothing: an event published
while no worker was subscribed was dropped without trace. A list keeps it until
the worker pops it.

### Fixed
- **Events lost while the worker was down.** Events published during a deploy
  restart, a crash or a Redis reconnect went to zero subscribers and were gone.
  They now wait in the list.
- **WebSocket worker going deaf after a Redis drop.** The worker read Redis
  through `clue/redis-react`'s lazy client, which clears its subscriptions when
  the connection drops and never re-subscribes. The process stayed up, kept
  logging stats, and received nothing until it was restarted. Every pop is now a
  fresh `BLPOP`, so the lazy client reconnects on the next one.
- **Events dropped while AppSync was unreachable.** The WebSocket worker kept
  receiving into an in-memory buffer and dropped events once it was full. It now
  stops popping while the WebSocket is not ready or the buffer is full, so the
  events stay in Redis.
- **HTTP worker ignored SIGTERM / SIGINT.** The handlers only logged, so the
  process kept running until it was force-killed. Both workers now finish
  the pop in flight (at most `block_timeout` seconds), flush and exit.
- **HTTP worker sent more than 5 events per request.** It published a whole
  channel's buffer (up to `batch_size`, 50 by default) in one request; AppSync
  accepts at most 5. Batches are now chunked to 5.
- **HTTP worker held a partial batch until the next event.** It now flushes as
  soon as the list runs dry.
- A batch the WebSocket client refuses to send now counts toward
  `events_dropped` instead of vanishing from the stats.

### Added
- `redis.key` (`APPSYNC_REDIS_KEY`): the list name. Defaults to
  `APPSYNC_REDIS_CHANNEL`, then `appsync:events`.
- `redis.max_length` (`APPSYNC_REDIS_MAX_LENGTH`, default 10000): the list is
  trimmed to this many events (oldest dropped first) so a worker that never comes
  back cannot fill Redis. `0` disables the cap.
- `redis.block_timeout` (`APPSYNC_REDIS_BLOCK_TIMEOUT`, default 1): seconds a
  worker waits on an empty list; also the longest a shutdown waits.
- `--redis-key` worker option.

### Deprecated
- `redis.channel` and `--redis-channel`. Both still work and name the list.

### Upgrading
Update the publishing app and the worker together. During the rollout, an old
worker does not read the list, so events queue there (up to `max_length`) and
the new worker delivers them when it starts. Events an old publisher sends over
Pub/Sub after the new worker starts are lost, exactly as before. A published
`config/appsync.php` keeps working; add the three new `redis` keys to change
their defaults.

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
