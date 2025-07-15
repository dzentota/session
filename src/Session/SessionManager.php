<?php

declare(strict_types=1);

namespace Dzentota\Session;

use DateTimeImmutable;
use Dzentota\Session\Cookie\CookieManagerInterface;
use Dzentota\Session\Exception\InvalidSessionIdException;
use Dzentota\Session\Exception\InvalidTokenException;
use Dzentota\Session\Storage\SessionStorageInterface;
use Dzentota\Session\Value\CsrfToken;
use Dzentota\Session\Value\SessionId;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Default implementation of SessionManagerInterface
 */
class SessionManager implements SessionManagerInterface
{
    private const CSRF_TOKEN_KEY = '_csrf_token';
    private const USER_AGENT_KEY = '_user_agent';
    private const IP_HASH_KEY = '_ip_hash';
    private const SESSION_COOKIE_NAME = 'Set-Cookie';

    private ?SessionState $state = null;
    private bool $initialized = false;

    /**
     * @param SessionStorageInterface $storage Storage adapter for session data
     * @param CookieManagerInterface $cookieManager Cookie manager for session cookies
     * @param int $idleTimeout Idle timeout in seconds (default: 30 minutes)
     * @param int $absoluteTimeout Absolute timeout in seconds (default: 4 hours)
     * @param bool $bindToIp Whether to bind session to IP address
     * @param bool $bindToUserAgent Whether to bind session to User-Agent header
     */
    public function __construct(
        private SessionStorageInterface $storage,
        private CookieManagerInterface $cookieManager,
        private int $idleTimeout = 1800,
        private int $absoluteTimeout = 14400,
        private bool $bindToIp = true,
        private bool $bindToUserAgent = true
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function start(ServerRequestInterface $request): SessionState
    {
        if ($this->initialized) {
            return $this->state;
        }

        // Extract session ID from cookie if present
        $sessionId = $this->getSessionIdFromRequest($request);

        // Generate a new session ID if none was found or if it's invalid
        if ($sessionId === null) {
            $this->state = new SessionState(SessionId::generate());
            $this->initialized = true;
            return $this->state;
        }

        // Attempt to load session data
        $data = $this->storage->read($sessionId);

        // If no data found, create a new session
        if ($data === null) {
            $this->state = new SessionState(SessionId::generate());
            $this->initialized = true;
            return $this->state;
        }

        // Unserialize session data
        $sessionData = $this->unserializeData($data);

        // Extract session metadata
        $createdAt = isset($sessionData['_created_at'])
            ? new DateTimeImmutable($sessionData['_created_at'])
            : new DateTimeImmutable();

        $lastActivityAt = isset($sessionData['_last_activity_at'])
            ? new DateTimeImmutable($sessionData['_last_activity_at'])
            : new DateTimeImmutable();

        // Remove internal metadata from the user data array
        unset(
            $sessionData['_created_at'],
            $sessionData['_last_activity_at']
        );

        // Check idle timeout
        $now = new DateTimeImmutable();
        if ($now->getTimestamp() - $lastActivityAt->getTimestamp() > $this->idleTimeout) {
            // Session expired due to inactivity
            $this->storage->destroy($sessionId);
            $this->state = new SessionState(SessionId::generate());
            $this->initialized = true;
            return $this->state;
        }

        // Check absolute timeout
        if ($now->getTimestamp() - $createdAt->getTimestamp() > $this->absoluteTimeout) {
            // Session expired due to absolute timeout
            $this->storage->destroy($sessionId);
            $this->state = new SessionState(SessionId::generate());
            $this->initialized = true;
            return $this->state;
        }

        // Verify session binding if enabled
        if (!$this->verifySessionBinding($request, $sessionData)) {
            // Session binding mismatch - potential hijacking attempt
            $this->storage->destroy($sessionId);
            $this->state = new SessionState(SessionId::generate());
            $this->initialized = true;
            return $this->state;
        }

        // Create session state with loaded data
        $this->state = new SessionState(
            $sessionId,
            $sessionData,
            $createdAt,
            $now // Update last activity time
        );

        $this->initialized = true;
        return $this->state;
    }

    /**
     * {@inheritDoc}
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $this->ensureInitialized();
        return $this->state->get($key, $default);
    }

    /**
     * {@inheritDoc}
     */
    public function set(string $key, mixed $value): void
    {
        $this->ensureInitialized();
        $this->state = $this->state->with($key, $value);
    }

    /**
     * {@inheritDoc}
     */
    public function remove(string $key): void
    {
        $this->ensureInitialized();
        $this->state = $this->state->without($key);
    }

    /**
     * {@inheritDoc}
     */
    public function regenerateId(): void
    {
        $this->ensureInitialized();

        // Save the old ID for migration
        $oldId = $this->state->getId();

        // Create a new state with regenerated ID
        $this->state = $this->state->withRegeneratedId();

        // Copy session data to new ID
        $serializedData = $this->serializeData($this->prepareDataForStorage($this->state));
        $this->storage->write($this->state->getId(), $serializedData, $this->absoluteTimeout);

        // Mark old session with short grace period
        $graceLifetime = 10; // 10 seconds grace period
        $this->storage->write($oldId, $serializedData, $graceLifetime);
    }

    /**
     * {@inheritDoc}
     */
    public function clear(): void
    {
        $this->ensureInitialized();
        $this->state = $this->state->withClearedData();
    }

    /**
     * {@inheritDoc}
     */
    public function destroy(): void
    {
        $this->ensureInitialized();

        // Delete from storage
        $this->storage->destroy($this->state->getId());

        // Mark as destroyed in state
        $this->state = $this->state->withDestroyed();
    }

    /**
     * {@inheritDoc}
     */
    public function getState(): SessionState
    {
        $this->ensureInitialized();
        return $this->state;
    }

    /**
     * {@inheritDoc}
     */
    public function generateCsrfToken(): CsrfToken
    {
        $this->ensureInitialized();

        $token = CsrfToken::generate();

        // Store only the hash in the session
        $this->state = $this->state->with(self::CSRF_TOKEN_KEY, $token->getHash());

        return $token;
    }

    /**
     * {@inheritDoc}
     */
    public function isCsrfTokenValid(string $submittedToken): bool
    {
        $this->ensureInitialized();

        $storedHash = $this->state->get(self::CSRF_TOKEN_KEY);
        if ($storedHash === null) {
            return false;
        }

        try {
            $token = CsrfToken::fromNative($submittedToken);
            // Compare the hash of the submitted token with the stored hash
            return hash_equals($storedHash, $token->getHash());
        } catch (InvalidTokenException $e) {
            return false;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function commit(ResponseInterface $response): ResponseInterface
    {
        $this->ensureInitialized();

        // Only write to storage if the state is dirty
        if ($this->state->isDirty() && $this->state->getStatus() !== SessionState::STATUS_DESTROYED) {
            $data = $this->prepareDataForStorage($this->state);
            $serialized = $this->serializeData($data);
            $this->storage->write($this->state->getId(), $serialized, $this->absoluteTimeout);

            // Mark state as clean after saving
            $this->state = $this->state->withCleanState();
        }

        // Add cookie header to response
        $cookieHeader = $this->cookieManager->getCookieHeader($this->state);
        if ($cookieHeader !== null) {
            $response = $response->withHeader(self::SESSION_COOKIE_NAME, $cookieHeader);
        }

        return $response;
    }

    /**
     * Prepare session data for storage, including metadata
     */
    private function prepareDataForStorage(SessionState $state): array
    {
        $data = $state->getData();

        // Add metadata
        $data['_created_at'] = $state->getCreatedAt()->format('c');
        $data['_last_activity_at'] = $state->getLastActivityAt()->format('c');

        return $data;
    }

    /**
     * Serialize session data for storage
     */
    private function serializeData(array $data): string
    {
        return serialize($data);
    }

    /**
     * Unserialize session data from storage
     */
    private function unserializeData(string $data): array
    {
        $unserialized = unserialize($data);
        if (!is_array($unserialized)) {
            return [];
        }
        return $unserialized;
    }

    /**
     * Extract the session ID from a request
     */
    private function getSessionIdFromRequest(ServerRequestInterface $request): ?SessionId
    {
        $cookies = $request->getCookieParams();

        // Получаем имя куки из CookieManager
        // Это добавленный метод, который нам нужно реализовать в CookieManagerInterface и CookieManager
        $cookieName = $this->cookieManager->getCookieName();

        if (!isset($cookies[$cookieName])) {
            return null;
        }

        try {
            return SessionId::fromNative($cookies[$cookieName]);
        } catch (InvalidSessionIdException $e) {
            return null;
        }
    }

    /**
     * Verify session binding to client characteristics
     */
    private function verifySessionBinding(ServerRequestInterface $request, array $sessionData): bool
    {
        // Check User-Agent binding
        if ($this->bindToUserAgent && isset($sessionData[self::USER_AGENT_KEY])) {
            $currentUserAgent = $request->getHeaderLine('User-Agent');
            if (!hash_equals($sessionData[self::USER_AGENT_KEY], $currentUserAgent)) {
                return false;
            }
        }

        // Check IP binding
        if ($this->bindToIp && isset($sessionData[self::IP_HASH_KEY])) {
            $currentIp = SessionSecurity::getClientIp($request->getServerParams());
            $currentIpHash = SessionSecurity::hashIpAddress($currentIp);
            if (!hash_equals($sessionData[self::IP_HASH_KEY], $currentIpHash)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Create session binding data based on client characteristics
     */
    private function createSessionBinding(ServerRequestInterface $request, array $sessionData): array
    {
        // Bind to User-Agent
        if ($this->bindToUserAgent) {
            $userAgent = $request->getHeaderLine('User-Agent');
            $sessionData[self::USER_AGENT_KEY] = $userAgent;
        }

        // Bind to IP address (hashed)
        if ($this->bindToIp) {
            $ip = SessionSecurity::getClientIp($request->getServerParams());
            $sessionData[self::IP_HASH_KEY] = SessionSecurity::hashIpAddress($ip);
        }

        return $sessionData;
    }

    /**
     * Ensure that the session has been initialized
     * @throws \LogicException
     */
    private function ensureInitialized(): void
    {
        if (!$this->initialized) {
            throw new \LogicException(
                'Session has not been initialized. Call start() first.'
            );
        }
    }
}
