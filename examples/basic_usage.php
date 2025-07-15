<?php

/**
 * Basic example of using SessionManager
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/FileCache.php';

use Dzentota\Session\Cookie\CookieManager;
use Dzentota\Session\Examples\FileCache;
use Dzentota\Session\SessionManager;
use Dzentota\Session\Storage\CacheStorage;
use Dzentota\Session\Value\CsrfToken;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;


// Create a request for the example
$request = new ServerRequest('GET', 'https://example.com');

// Initialize components
$cache = new FileCache();
$storage = new CacheStorage($cache);
$cookieManager = new CookieManager(
    'session',    // cookie name
    true,         // secure (HTTPS only)
    true,         // httpOnly
    'Lax',        // SameSite policy
    '/',          // path
    3600          // cookie lifetime in seconds
);

// Create session manager
$sessionManager = new SessionManager(
    $storage,
    $cookieManager,
    1800,   // idle timeout in seconds (30 minutes)
    14400   // absolute lifetime in seconds (4 hours)
);

// Initialize session
$state = $sessionManager->start($request);
echo "Session initialized. Session ID: {$state->getId()}" . PHP_EOL;

// Save data to session
$sessionManager->set('user_id', 123);
$sessionManager->set('user_name', 'John Doe');
$sessionManager->set('last_visit', date('Y-m-d H:i:s'));

echo "Data saved to session." . PHP_EOL;

// Read data from session
$userId = $sessionManager->get('user_id');
$userName = $sessionManager->get('user_name');
echo "Data from session: User ID = {$userId}, Name = {$userName}" . PHP_EOL;

// Generate CSRF token
$token = $sessionManager->generateCsrfToken();
echo "Generated CSRF token: {$token->toNative()}" . PHP_EOL;

// Verify CSRF token
$isValid = $sessionManager->isCsrfTokenValid($token->toNative());
echo "CSRF token verification: " . ($isValid ? "Valid" : "Invalid") . PHP_EOL;

// Regenerate session ID (for example, after user login)
$oldId = $sessionManager->getState()->getId();
$sessionManager->regenerateId();
$newId = $sessionManager->getState()->getId();
echo "Session ID regenerated. Old: {$oldId}, New: {$newId}" . PHP_EOL;

// Remove data from session
$sessionManager->remove('user_id');
echo "User ID removed from session. Current value: " .
     ($sessionManager->get('user_id') ?? 'null') . PHP_EOL;

// Create HTTP response and commit session
$response = new Response();
$response = $sessionManager->commit($response);

// Output Set-Cookie header
$setCookie = $response->getHeader('Set-Cookie')[0] ?? 'No Set-Cookie header';
echo "Set-Cookie header: {$setCookie}" . PHP_EOL;

// In a real application, the response would be sent to the client
// $emitter = new ResponseEmitter();
// $emitter->emit($response);
