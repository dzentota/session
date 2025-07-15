<?php

/**
 * Simple file-based PSR-16 cache implementation for examples
 */

declare(strict_types=1);

namespace Dzentota\Session\Examples;

use Psr\SimpleCache\CacheInterface;

class FileCache implements CacheInterface
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
        // Print debug information for keys related to sessions
        if (strpos($key, 'session_') === 0) {
            echo "<!-- DEBUG: Getting cache for key: $key -->\n";
            echo "<!-- DEBUG: MD5 hash for this key: " . md5($key) . " -->\n";
        }

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
        // Print debug information for keys related to sessions
        if (strpos($key, 'session_') === 0) {
            echo "<!-- DEBUG: Setting cache for key: $key -->\n";
            echo "<!-- DEBUG: MD5 hash for this key: " . md5($key) . " -->\n";
        }

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
