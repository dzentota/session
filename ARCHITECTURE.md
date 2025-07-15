# Session Management Library Architecture

This document outlines the architecture of the session management library, explaining its key components and how they interact.

## Overview

The session management library provides a secure, flexible, and PSR-compatible approach to handling sessions in PHP applications. It follows modern design principles including:

- Dependency injection
- Interface-based design
- Value objects
- Middleware support
- Configurable storage backends

## Core Components

### Session Management

- `SessionManager` - The central component that coordinates session operations
- `SessionManagerInterface` - Defines the contract for session management
- `SessionState` - Represents the current state of a session, including data and metadata
- `SessionSecurity` - Provides security-related utilities for session management
- `SessionEncryptor` - Handles encryption and decryption of session data

### Value Objects

- `SessionId` - Encapsulates and validates session identifiers
- `CsrfToken` - Represents CSRF tokens for form protection

### Storage

The library supports different storage backends through the `SessionStorageInterface`:

- `CacheStorage` - Stores session data using any PSR-16 compatible cache (Redis, Memcached, file-based, etc.)
- `DatabaseStorage` - Stores session data in a database

### Cookie Management

Cookie handling is abstracted through:

- `CookieManagerInterface` - Defines methods for cookie operations
- `CookieManager` - Default implementation with secure defaults

### Middleware

- `SessionMiddleware` - PSR-15 compatible middleware for session handling in middleware-based applications

## Data Flow

1. **Session Start**:
   - The `SessionMiddleware` or direct `SessionManager` use extracts the session ID from request cookie
   - Storage backend is queried for session data using this ID
   - If no valid session exists, a new one is created

2. **Session Use**:
   - Application reads/writes session data through `SessionManager`
   - The `SessionState` tracks changes to session data

3. **Session Commit**:
   - Session data is serialized and potentially encrypted
   - Data is written to the configured storage backend
   - A session cookie is added to the response

## Security Features

The library implements several security features:

- **Session ID regeneration** - Ability to generate new session IDs while preserving data
- **CSRF protection** - Built-in CSRF token generation and validation
- **Session binding** - Optional binding of sessions to IP address and/or User-Agent
- **Encryption** - Optional encryption of session data
- **Secure cookies** - HttpOnly, SameSite, and Secure cookie flags
- **Absolute timeout** - Maximum lifetime for a session
- **Idle timeout** - Timeout after period of inactivity

## Usage Patterns

### Basic Usage

```php
$storage = new CacheStorage($cache);
$cookieManager = new CookieManager('session');
$sessionManager = new SessionManager($storage, $cookieManager);

// Start the session
$state = $sessionManager->start($request);

// Use the session
$sessionManager->set('user_id', 123);
$userId = $sessionManager->get('user_id');

// Commit the session
$response = $sessionManager->commit($response);
```

### Middleware-Based Usage

```php
// Create the middleware
$sessionMiddleware = new SessionMiddleware($storage, $cookieManager);

// Add to middleware stack
$app->add($sessionMiddleware);

// In a request handler or controller
$sessionManager = $request->getAttribute('session');
$sessionManager->set('visit_count', $sessionManager->get('visit_count', 0) + 1);
```

## Extending the Library

The library is designed for extensibility:

- Create custom storage backends by implementing `SessionStorageInterface`
- Implement custom cookie management by implementing `CookieManagerInterface`
- Add custom encryption by providing encryptor and decryptor callbacks to `CacheStorage`

## File Structure

```
src/
├── Session/
│   ├── SessionEncryptor.php        # Encryption utilities
│   ├── SessionManager.php          # Main session management implementation
│   ├── SessionManagerInterface.php # Session management contract
│   ├── SessionSecurity.php         # Security utilities
│   ├── SessionState.php            # Session state representation
│   ├── Cookie/
│   │   ├── CookieManager.php       # Default cookie implementation
│   │   └── CookieManagerInterface.php # Cookie management contract
│   ├── Exception/
│   │   ├── InvalidSessionIdException.php
│   │   └── InvalidTokenException.php
│   ├── Middleware/
│   │   └── SessionMiddleware.php   # PSR-15 middleware
│   ├── Storage/
│   │   ├── CacheStorage.php        # PSR-16 cache storage
│   │   ├── DatabaseStorage.php     # Database storage
│   │   └── SessionStorageInterface.php # Storage contract
│   └── Value/
│       ├── CsrfToken.php           # CSRF token value object
│       └── SessionId.php           # Session ID value object
```

## Dependencies

- PSR-16: Simple Cache Interface - For cache-based storage
- PSR-7: HTTP Message Interface - For request/response handling
- PSR-15: HTTP Server Middleware - For middleware support

## Design Decisions

- **Stateless Design**: The session manager does not keep global state, making it easier to test and use in various contexts
- **Immutability**: Value objects are immutable to prevent unexpected changes
- **Separation of Concerns**: Storage, cookie management, and session handling are separated into discrete components
- **Defensive Programming**: Extensive validation and error checking for security
- **Composition Over Inheritance**: Components are composed rather than extended
