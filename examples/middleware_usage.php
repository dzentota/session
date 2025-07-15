<?php

/**
 * Example of using SessionMiddleware in a PSR-15 compatible application
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/FileCache.php';

use Dzentota\Session\Cookie\CookieManager;
use Dzentota\Session\Examples\FileCache;
use Dzentota\Session\Middleware\SessionMiddleware;
use Dzentota\Session\SessionManager;
use Dzentota\Session\Storage\CacheStorage;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

// Simple implementation of PSR-15 RequestHandler for the example
class SimpleRequestHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // Get session manager from request attributes
        $sessionManager = $request->getAttribute(SessionMiddleware::SESSION_ATTRIBUTE);

        $response = new Response();
        $body = $response->getBody();

        // Check if session is initialized
        if (!$sessionManager instanceof SessionManager) {
            $body->write('Error: SessionManager not found in request attributes');
            return $response->withStatus(500);
        }

        // Get current values from session
        $visits = $sessionManager->get('visit_count', 0);
        $lastVisit = $sessionManager->get('last_visit', '');

        // Check action from POST request
        $action = '';
        $parsedBody = $request->getParsedBody();
        if (is_array($parsedBody) && isset($parsedBody['action'])) {
            $action = $parsedBody['action'];

            if ($action === 'regenerate') {
                // Regenerate session ID - this preserves session data automatically
                $sessionManager->regenerateId();
                // No need to reset the counter - data is preserved during regeneration
            } elseif ($action === 'clear_cache') {
                // Clear the cache by removing all cache files
                $cacheDir = sys_get_temp_dir() . '/session_cache';
                if (is_dir($cacheDir)) {
                    $files = glob($cacheDir . '/*.cache');
                    foreach ($files as $file) {
                        unlink($file);
                    }
                }

                // Clear current session data
                $sessionManager->clear();
                $visits = 0; // Reset counter
                $sessionManager->set('visit_count', $visits);
                $sessionManager->set('last_visit', date('Y-m-d H:i:s'));
            }
        } else {
            // Only increment visit count for new sessions or after a certain delay
            // to avoid marking the session as dirty on every request
            if ($visits === 0 || empty($lastVisit)) {
                // First visit - initialize the counter
                $visits = 1;
                $sessionManager->set('visit_count', $visits);
                $sessionManager->set('last_visit', date('Y-m-d H:i:s'));
            } else {
                $visits++;
                $sessionManager->set('visit_count', $visits);
                $sessionManager->set('last_visit', date('Y-m-d H:i:s'));
            }
        }

        // Create response with data from session
        $body->write(<<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>SessionMiddleware Example</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 40px;
            line-height: 1.6;
            color: #333;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background-color: #f9f9f9;
            padding: 20px;
            border-radius: 5px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        h1 {
            color: #4b6584;
            border-bottom: 1px solid #ddd;
            padding-bottom: 10px;
        }
        .session-info {
            background-color: #fff;
            padding: 15px;
            border-radius: 4px;
            border-left: 4px solid #4b6584;
            margin-bottom: 20px;
        }
        .session-id {
            color: #4b6584;
            font-family: monospace;
            background: #eee;
            padding: 3px 6px;
            border-radius: 3px;
        }
        .button-container {
            margin-top: 20px;
        }
        .button {
            display: inline-block;
            padding: 8px 16px;
            margin-right: 10px;
            background-color: #4b6584;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
        }
        .button:hover {
            background-color: #3c526d;
        }
        .danger {
            background-color: #e74c3c;
        }
        .danger:hover {
            background-color: #c0392b;
        }
        .action-form {
            display: inline;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>SessionMiddleware Example</h1>
        <div class="session-info">
            <p>Your session ID: <span class="session-id">{$sessionManager->getState()->getId()}</span></p>
            <p>You have visited this page <strong>{$visits} time(s)</strong>.</p>
            <p>Last visit: <strong>{$sessionManager->get('last_visit')}</strong></p>
        </div>
        
        <div class="button-container">
            <form method="post" class="action-form">
                <input type="hidden" name="action" value="regenerate">
                <button type="submit" class="button">Regenerate Session ID</button>
            </form>
            
            <form method="post" class="action-form">
                <input type="hidden" name="action" value="clear_cache">
                <button type="submit" class="button danger">Clear Cache</button>
            </form>
        </div>
    </div>
</body>
</html>
HTML);

        return $response;
    }
}

// Output buffering to prevent headers from being sent too early
ob_start();

// Initialize session components
try {
    // Create temporary directory for session cache if it doesn't exist
    $tempDir = sys_get_temp_dir() . '/session_cache';
    if (!is_dir($tempDir)) {
        mkdir($tempDir, 0777, true);
    }

    // Initialize the cache system
    $cache = new FileCache($tempDir);

    // Create session storage - fixed, added null values for encryptor and decryptor
    $storage = new CacheStorage($cache, 'session_', null, null);

    // Setup cookie manager
    $cookieManager = new CookieManager(
        'session',   // cookie name
        false,       // secure - set to false for local development
        true,        // httpOnly
        'Lax',       // sameSite
        '/',         // path
        3600         // lifetime (1 hour)
    );

    // Initialize session manager
    $sessionManager = new SessionManager($storage, $cookieManager);

    // Create PSR-7 factory for creating request and response objects
    $psr17Factory = new Psr17Factory();

    // Create the session middleware
    $sessionMiddleware = new SessionMiddleware($storage, $cookieManager);

    // Creating request properly using factory
    $serverRequestFactory = new Psr17Factory();
    $uploadedFileFactory = new Psr17Factory();
    $streamFactory = new Psr17Factory();

    // Creating request from global variables
    $request = new ServerRequest(
        $_SERVER['REQUEST_METHOD'] ?? 'GET',
        $_SERVER['REQUEST_URI'] ?? '/',
        $_SERVER,
        null,
        '1.1',
        $_SERVER
    );

    // Adding query parameters
    $uri = $request->getUri();
    $query = $_SERVER['QUERY_STRING'] ?? '';
    if ($query) {
        $uri = $uri->withQuery($query);
        $request = $request->withUri($uri);
    }

    // Adding cookies
    if (!empty($_COOKIE)) {
        $request = $request->withCookieParams($_COOKIE);
    }

    // Processing request body for POST requests
    if (isset($_SERVER['REQUEST_METHOD']) && in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT', 'PATCH'])) {
        $request = $request->withParsedBody($_POST);
    }

    // Create the request handler
    $handler = new SimpleRequestHandler();

    // Process the request through middleware
    $response = $sessionMiddleware->process($request, $handler);

} catch (Exception $e) {
    $errorMsg = "Error: " . $e->getMessage() . "\n";
    $errorMsg .= "Stack trace: " . $e->getTraceAsString() . "\n";
}

// Now we can display debug information
// Debugging
echo "<pre style='background:#eee;padding:10px;margin-bottom:20px;'>";
echo "<b>Debugging Session:</b>\n";
echo "Session cookie from browser: " . ($_COOKIE['session'] ?? 'Not set') . "\n";
echo "Temporary directory for cache: " . $tempDir . "/session_cache\n";

// Check if cache file exists for the current session
if (isset($_COOKIE['session'])) {
    $sessionIdFromCookie = $_COOKIE['session'];
    $cachePath = $tempDir . "/" . md5('session_' . $sessionIdFromCookie) . '.cache';
    echo "Checking for cache file: $cachePath\n";
    echo "Cache file exists: " . (file_exists($cachePath) ? 'Yes' : 'No') . "\n";

    if (file_exists($cachePath)) {
        $content = file_get_contents($cachePath);
        $data = unserialize($content);
        echo "Cache file contents: " . print_r($data, true) . "\n";
    }
}
echo "</pre>";

// Debug information
echo "<pre style='background:#f5f5f5;padding:10px;margin:10px;border:1px solid #ddd;'>";
echo "<b>Session Debug Information</b>\n";
echo "Cookies received: " . json_encode($_COOKIE, JSON_PRETTY_PRINT) . "\n";
echo "Cache directory: " . $tempDir . "\n";

// List all cache files
if (is_dir($tempDir)) {
    $files = glob($tempDir . '/*.cache');
    echo "Cache files (" . count($files) . "):\n";
    foreach ($files as $file) {
        echo "- " . basename($file) . " (size: " . filesize($file) . " bytes, modified: " . date("Y-m-d H:i:s", filemtime($file)) . ")\n";

        // Show content of each cache file
        $content = file_get_contents($file);
        $data = unserialize($content);
        echo "  Contents: " . json_encode($data, JSON_PRETTY_PRINT) . "\n";
    }
} else {
    echo "Cache directory does not exist yet.\n";
}
echo "</pre>";

// Response debug
echo "<pre style='background:#eee;padding:10px;margin-top:20px;'>";
echo "<b>Response Debug:</b>\n";

if (isset($errorMsg)) {
    // If an error occurred during processing, display it
    echo $errorMsg;
    echo "</pre>";
    exit;
}

// Display response headers (for debugging)
echo "Response Headers:\n";
foreach ($response->getHeaders() as $name => $values) {
    echo "$name: " . implode(", ", $values) . "\n";
}

// Find and parse Set-Cookie header
$setCookieHeaders = $response->getHeader('Set-Cookie');
if (!empty($setCookieHeaders)) {
    $cookieString = $setCookieHeaders[0];
    echo "Parsed cookie:\n";
    preg_match('/session=([^;]+)/', $cookieString, $matches);
    if (!empty($matches)) {
        $newSessionId = $matches[1];
        echo "Name: session\n";
        echo "Value: $newSessionId\n";
        echo "New Session ID: $newSessionId\n";

        // Check if the cache file for this new session exists
        $newCachePath = $tempDir . '/' . md5('session_' . $newSessionId) . '.cache';
        echo "Cache path for new ID: $newCachePath\n";
        echo "Cache file for new ID exists: " . (file_exists($newCachePath) ? 'Yes' : 'No') . "\n";
    }
}
echo "</pre>";

// Output the actual response
foreach ($response->getHeaders() as $name => $values) {
    foreach ($values as $value) {
        header("$name: $value", false);
    }
}

// Send all buffered data
ob_end_flush();

// Output response body
echo $response->getBody();
