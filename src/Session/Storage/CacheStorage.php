<?php

declare(strict_types=1);

namespace Dzentota\Session\Storage;

use Dzentota\Session\Value\SessionId;
use Psr\SimpleCache\CacheInterface;

/**
 * Cache storage adapter for session data with encryption support
 */
class CacheStorage implements SessionStorageInterface
{
    /**
     * @param CacheInterface $cache PSR-16 cache implementation
     * @param string $prefix Key prefix for session data in cache
     * @param callable|null $encryptor Optional callback for encrypting data (function(string $data): string)
     * @param callable|null $decryptor Optional callback for decrypting data (function(string $encryptedData): string)
     */
    public function __construct(
        private CacheInterface $cache,
        private string $prefix = 'session_',
        private mixed $encryptor = null,
        private mixed $decryptor = null
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function read(SessionId $sessionId): ?string
    {
        $key = $this->getKey($sessionId);
        $data = $this->cache->get($key);

        if ($data === null) {
            return null;
        }

        // Decrypt data if a decryptor is provided
        if ($this->decryptor !== null) {
            return ($this->decryptor)($data);
        }

        return $data;
    }

    /**
     * {@inheritDoc}
     */
    public function write(SessionId $sessionId, string $data, int $lifetime): bool
    {
        $key = $this->getKey($sessionId);

        // Encrypt data if an encryptor is provided
        $dataToStore = ($this->encryptor !== null) ? ($this->encryptor)($data) : $data;

        return $this->cache->set($key, $dataToStore, $lifetime);
    }

    /**
     * {@inheritDoc}
     */
    public function destroy(SessionId $sessionId): bool
    {
        $key = $this->getKey($sessionId);
        return $this->cache->delete($key);
    }

    /**
     * {@inheritDoc}
     */
    public function gc(int $maxLifetime): bool
    {
        // No need for manual garbage collection in most cache systems
        // as they handle expiration automatically based on the TTL
        return true;
    }

    /**
     * Get the cache key for a session ID
     */
    private function getKey(SessionId $sessionId): string
    {
        return $this->prefix . $sessionId->toNative();
    }
}
