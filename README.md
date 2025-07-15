# PHP Secure Session Library

A secure and flexible session management library for PHP applications with PSR-7, PSR-15, and PSR-16 support.

## Features

- 🔒 **Complete Security**: CSRF protection, data encryption, session hijacking prevention
- 🧱 **Modular Architecture**: Flexible choice of session storage (cache, database)
- 🚀 **High Performance**: Optimized session lifetime management
- 🔌 **PSR Compatibility**: Integration with any PSR-7/PSR-15 framework
- 🧪 **Full Test Coverage**: Reliability and stability guaranteed

## Installation

```bash
composer require dzentota/session
```

## Quick Start

### Basic Usage

```php
use Dzentota\Session\SessionManager;
use Dzentota\Session\Cookie\CookieManager;
use Dzentota\Session\Storage\CacheStorage;
use Psr\SimpleCache\CacheInterface;

// Initialize dependencies
$cache = new YourPsrCacheImplementation();
$storage = new CacheStorage($cache);
$cookieManager = new CookieManager('session', '/', 3600, true, true, 'Lax');

// Create session manager
$sessionManager = new SessionManager($storage, $cookieManager);

// Work with session data
$sessionManager->start($request);
$sessionManager->set('user_id', 123);
$userId = $sessionManager->get('user_id');

// Add CSRF protection
$token = $sessionManager->generateCsrfToken();
// Insert the token into your application forms:
// <input type="hidden" name="csrf_token" value="<?= $token->toNative() ?>">

// Verify token when processing the form
if ($sessionManager->isCsrfTokenValid($_POST['csrf_token'])) {
    // Continue form processing
} else {
    // Potential CSRF attack
}

// Commit session and add cookie to response
$response = $sessionManager->commit($response);
```

### Usage in PSR-15 Compatible Applications

```php
use Dzentota\Session\Middleware\SessionMiddleware;
use Dzentota\Session\Cookie\CookieManager;
use Dzentota\Session\Storage\CacheStorage;

// Create session middleware
$sessionMiddleware = new SessionMiddleware(
    new CacheStorage($cache),
    new CookieManager('session', '/', 3600, true, true, 'Lax'),
    1800,   // idle timeout in seconds (30 minutes)
    14400   // absolute lifetime in seconds (4 hours)
);

// Add middleware to your application
$app->add($sessionMiddleware);

// In request handlers, you can access the session:
$handler = function (ServerRequestInterface $request, RequestHandlerInterface $handler) {
    $session = $request->getAttribute(SessionMiddleware::SESSION_ATTRIBUTE);
    
    // Work with the session
    $session->set('last_visit', new DateTime());
    
    return $handler->handle($request);
};
```

## Session Data Encryption

```php
use Dzentota\Session\SessionEncryptor;
use Dzentota\Session\Storage\CacheStorage;

// Generate encryption key (store it securely)
$key = SessionEncryptor::generateKey();

// Create encryptor
$encryptor = new SessionEncryptor($key);

// Create storage with encryption
$storage = new CacheStorage(
    $cache,
    'session_',
    [$encryptor, 'encrypt'],
    [$encryptor, 'decrypt']
);

// Use $storage as usual
```

## Database Session Storage

```php
use Dzentota\Session\Storage\DatabaseStorage;

// Create the table in your database:
// 
// CREATE TABLE sessions (
//     session_id VARCHAR(36) PRIMARY KEY,
//     session_data BLOB NOT NULL,
//     expires_at INT UNSIGNED NOT NULL,
//     created_at INT UNSIGNED NOT NULL
// );

// Initialize PDO
$pdo = new PDO('mysql:host=localhost;dbname=myapp', 'username', 'password');

// Create storage
$storage = new DatabaseStorage($pdo, 'sessions');

// Use storage with session manager
$sessionManager = new SessionManager($storage, $cookieManager);
```

## Security

### Session ID Regeneration

It's recommended to regenerate the session ID after authentication or privilege changes:

```php
// After successful user login:
if ($authentication->isValid($username, $password)) {
    $sessionManager->regenerateId();
    $sessionManager->set('user', $user);
}
```

### Session Binding to Client

By default, sessions are bound to the client's IP address and User-Agent, increasing security:

```php
$sessionManager = new SessionManager(
    $storage,
    $cookieManager,
    1800,   // idle timeout
    14400,  // absolute lifetime
    true,   // IP address binding
    true    // User-Agent binding
);
```

### Cookie Security Settings

```php
// Create secure cookie manager
$cookieManager = new CookieManager(
    '__Host-session',  // __Host- prefix enhances security
    '/',               // path
    3600,              // cookie lifetime in seconds (1 hour)
    true,              // secure (HTTPS only)
    true,              // httpOnly (not accessible via JavaScript)
    'Strict'           // SameSite policy (Strict, Lax, or None)
);
```

## Usage Examples with Popular Frameworks

### Slim 4

```php
use Slim\Factory\AppFactory;
use Dzentota\Session\Middleware\SessionMiddleware;

$app = AppFactory::create();

// Add session middleware
$app->add(new SessionMiddleware(
    new CacheStorage($cache),
    new CookieManager('session')
));

// Use in routes
$app->get('/profile', function ($request, $response, $args) {
    $session = $request->getAttribute(SessionMiddleware::SESSION_ATTRIBUTE);
    
    $userId = $session->get('user_id');
    if (!$userId) {
        return $response->withHeader('Location', '/login')->withStatus(302);
    }
    
    // ...
});
```

### Laravel (with PSR-7 bridge)

```php
use Laravel\Lumen\Application;
use Dzentota\Session\SessionManager;
use Dzentota\Session\Cookie\CookieManager;
use Dzentota\Session\Storage\CacheStorage;
use Illuminate\Support\Facades\Cache;

$app = new Application();

// Create adapter for Laravel cache
$cacheAdapter = new class(Cache::store()) implements \Psr\SimpleCache\CacheInterface {
    // PSR-16 interface implementation
};

// Register SessionManager in the container
$app->singleton('session', function ($app) use ($cacheAdapter) {
    return new SessionManager(
        new CacheStorage($cacheAdapter),
        new CookieManager('session')
    );
});

// Use in controllers:
// $session = app('session');
```

## License

MIT
