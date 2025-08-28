# Changelog

All notable changes to `laravel-appsync-broadcaster` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Improved
- Configuration publishing now only merges package config when not published
- Updated README to clarify automatic configuration registration
- Enhanced configuration file documentation

### Added
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
