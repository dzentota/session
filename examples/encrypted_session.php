<?php

/**
 * Example of session data encryption
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Dzentota\Session\Cookie\CookieManager;
use Dzentota\Session\SessionEncryptor;
use Dzentota\Session\SessionManager;
use Dzentota\Session\Storage\CacheStorage;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;

// Create a file-based implementation of CacheInterface for the example
class FileCache implements \Psr\SimpleCache\CacheInterface
{
    private string $directory;

    public function __construct(string $directory = null)
    {
        // Use system temp directory if none provided
        $this->directory = $directory ?? sys_get_temp_dir() . '/session_cache';

        // Create directory if it doesn't exist
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0777, true);
        }
    }

    private function getFilePath(string $key): string
    {
        return $this->directory . '/' . md5($key) . '.cache';
    }

    private function isExpired(string $filePath, $ttl): bool
    {
        if ($ttl === null) {
            return false;
        }

        $modifiedTime = filemtime($filePath);
        return (time() - $modifiedTime) > $ttl;
    }

    public function get($key, $default = null)
    {
        $filePath = $this->getFilePath($key);

        if (!file_exists($filePath)) {
            return $default;
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return $default;
        }

        $data = unserialize($content);

        // Check if data is expired
        if (isset($data['ttl']) && $this->isExpired($filePath, $data['ttl'])) {
            unlink($filePath); // Remove expired file
            return $default;
        }

        return $data['value'] ?? $default;
    }

    public function set($key, $value, $ttl = null)
    {
        $filePath = $this->getFilePath($key);
        $data = [
            'value' => $value,
            'ttl' => $ttl
        ];

        return file_put_contents($filePath, serialize($data)) !== false;
    }

    public function delete($key)
    {
        $filePath = $this->getFilePath($key);

        if (file_exists($filePath)) {
            return unlink($filePath);
        }

        return true;
    }

    public function clear()
    {
        $files = glob($this->directory . '/*.cache');

        foreach ($files as $file) {
            unlink($file);
        }

        return true;
    }

    public function getMultiple($keys, $default = null)
    {
        $result = [];

        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }

        return $result;
    }

    public function setMultiple($values, $ttl = null)
    {
        $success = true;

        foreach ($values as $key => $value) {
            $success = $success && $this->set($key, $value, $ttl);
        }

        return $success;
    }

    public function deleteMultiple($keys)
    {
        $success = true;

        foreach ($keys as $key) {
            $success = $success && $this->delete($key);
        }

        return $success;
    }

    public function has($key)
    {
        $filePath = $this->getFilePath($key);

        if (!file_exists($filePath)) {
            return false;
        }

        if (!$content = file_get_contents($filePath)) {
            return false;
        }

        $data = unserialize($content);

        // Check if data is expired
        if (isset($data['ttl']) && $this->isExpired($filePath, $data['ttl'])) {
            unlink($filePath); // Remove expired file
            return false;
        }

        return true;
    }
}

// Create a request for the example
$request = new ServerRequest('GET', 'https://example.com');

// Generate encryption key (in a real application, store it securely)
$encryptionKey = SessionEncryptor::generateKey();
echo "Generated encryption key (for demonstration): " . bin2hex($encryptionKey) . PHP_EOL;

// Create encryptor
$encryptor = new SessionEncryptor($encryptionKey);

// Demonstrate encryption and decryption
$testData = "User's confidential data";
$encrypted = $encryptor->encrypt($testData);
$decrypted = $encryptor->decrypt($encrypted);

echo "Encryption test:" . PHP_EOL;
echo "Original data: {$testData}" . PHP_EOL;
echo "Encrypted data: {$encrypted}" . PHP_EOL;
echo "Decrypted data: {$decrypted}" . PHP_EOL;
echo "Encryption works: " . ($testData === $decrypted ? "Yes" : "No") . PHP_EOL . PHP_EOL;

// Initialize cache
$cache = new FileCache();

// Create session storage with encryption
$storage = new CacheStorage(
    $cache,
    'session_',
    [$encryptor, 'encrypt'], // encryption function
    [$encryptor, 'decrypt']  // decryption function
);

// Create cookie manager
$cookieManager = new CookieManager(
    'session',    // cookie name
    true,         // secure (HTTPS only)
    true,         // httpOnly
    'Lax',        // SameSite policy
    '/',          // path
    3600          // cookie lifetime in seconds
);

// Create session manager with encrypted storage
$sessionManager = new SessionManager($storage, $cookieManager);

// Initialize session
$state = $sessionManager->start($request);
echo "Session with encryption initialized. Session ID: {$state->getId()}" . PHP_EOL;

// Store confidential data in session
$sessionManager->set('credit_card', '1234-5678-9012-3456');
$sessionManager->set('password_hash', password_hash('secret_password', PASSWORD_DEFAULT));
$sessionManager->set('personal_data', [
    'full_name' => 'John Doe',
    'ssn' => '123-45-6789',
    'birth_date' => '1980-01-01'
]);

echo "Confidential data stored in session (encrypted)." . PHP_EOL;

// Read data from session (automatically decrypted)
$creditCard = $sessionManager->get('credit_card');
$personalData = $sessionManager->get('personal_data');

echo "Data successfully read from session and decrypted:" . PHP_EOL;
echo "Credit card number: {$creditCard}" . PHP_EOL;
echo "Personal data: " . json_encode($personalData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;

// Create HTTP response and commit session
$response = new Response();
$response = $sessionManager->commit($response);

// Note: In a real application, the response would be sent to the client
// and on the next request, the data would be retrieved from storage,
// decrypted, and available for use again

echo PHP_EOL . "Note: In a real application, the encryption key should be stored in a secure location" . PHP_EOL;
echo "and should never be displayed in output or logs!" . PHP_EOL;
