<?php

declare(strict_types=1);

namespace Dzentota\Session\Storage;

use Dzentota\Session\Value\SessionId;

/**
 * Interface for session storage adapters
 */
interface SessionStorageInterface
{
    /**
     * Read session data from storage
     *
     * @param SessionId $sessionId The session identifier
     * @return string|null The serialized session data, or null if not found
     */
    public function read(SessionId $sessionId): ?string;

    /**
     * Write session data to storage
     *
     * @param SessionId $sessionId The session identifier
     * @param string $data The serialized session data
     * @param int $lifetime The session lifetime in seconds
     * @return bool True on success, false on failure
     */
    public function write(SessionId $sessionId, string $data, int $lifetime): bool;

    /**
     * Destroy session data in storage
     *
     * @param SessionId $sessionId The session identifier
     * @return bool True on success, false on failure
     */
    public function destroy(SessionId $sessionId): bool;

    /**
     * Garbage collection - remove expired sessions
     *
     * @param int $maxLifetime The maximum lifetime in seconds
     * @return bool True on success, false on failure
     */
    public function gc(int $maxLifetime): bool;
}
