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
    private const TOMBSTONE_KEY = '_regenerated';

    private ?SessionState $state = null;
    private bool $initialized = false;

    /**
     * Binding data derived from the client request, stored separately from user
     * session data so it is always re-persisted on commit without leaking into
     * the public getData() / get() API.
     *
     * @var array<string, string>
     */
    private array $bindingData = [];

    /**
     * @param SessionStorageInterface $storage Storage adapter for session data
     * @param CookieManagerInterface $cookieManager Cookie manager for session cookies
     * @param int $idleTimeout Idle timeout in seconds (default: 30 minutes)
     * @param int $absoluteTimeout Absolute timeout in seconds (default: 4 hours)
     * @param bool $bindToIp Whether to bind session to IP address
     * @param bool $bindToUserAgent Whether to bind session to User-Agent header
     * @param int $regenerateGracePeriod Seconds a tombstone is kept under the old session ID
     *        after regeneration (0 = destroy immediately). The tombstone never yields
     *        session data; requests presenting the old ID receive a fresh empty session.
     */
    public function __construct(
        private SessionStorageInterface $storage,
        private CookieManagerInterface $cookieManager,
        private int $idleTimeout = 1800,
        private int $absoluteTimeout = 14400,
        private bool $bindToIp = true,
        private bool $bindToUserAgent = true,
        private int $regenerateGracePeriod = 0
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

        // No cookie — brand-new session
        if ($sessionId === null) {
            $this->state = $this->createNewSession($request);
            $this->initialized = true;
            return $this->state;
        }

        // Attempt to load session data
        $data = $this->storage->read($sessionId);

        // Nothing in storage — brand-new session
        if ($data === null) {
            $this->state = $this->createNewSession($request);
            $this->initialized = true;
            return $this->state;
        }

        // Unserialize session data
        $sessionData = $this->unserializeData($data);

        // A tombstone left behind by regenerateId(): the old ID is dead and
        // must never yield session data. Start a fresh, unauthenticated session.
        if (isset($sessionData[self::TOMBSTONE_KEY])) {
            $this->state = $this->createNewSession($request);
            $this->initialized = true;
            return $this->state;
        }

        // Extract session metadata
        $createdAt = isset($sessionData['_created_at'])
            ? new DateTimeImmutable($sessionData['_created_at'])
            : new DateTimeImmutable();

        $lastActivityAt = isset($sessionData['_last_activity_at'])
            ? new DateTimeImmutable($sessionData['_last_activity_at'])
            : new DateTimeImmutable();

        // Strip internal metadata from the user-visible data array
        unset(
            $sessionData['_created_at'],
            $sessionData['_last_activity_at']
        );

        // Check idle timeout
        $now = new DateTimeImmutable();
        if ($now->getTimestamp() - $lastActivityAt->getTimestamp() > $this->idleTimeout) {
            $this->storage->destroy($sessionId);
            $this->state = $this->createNewSession($request);
            $this->initialized = true;
            return $this->state;
        }

        // Check absolute timeout
        if ($now->getTimestamp() - $createdAt->getTimestamp() > $this->absoluteTimeout) {
            $this->storage->destroy($sessionId);
            $this->state = $this->createNewSession($request);
            $this->initialized = true;
            return $this->state;
        }

        // Extract and strip binding data before building the user-visible state
        $storedBindingData = $this->extractBindingData($sessionData);
        unset($sessionData[self::USER_AGENT_KEY], $sessionData[self::IP_HASH_KEY]);

        // Verify session binding if enabled
        if (!$this->verifySessionBinding($request, $storedBindingData)) {
            // Session binding mismatch - potential hijacking attempt
            $this->storage->destroy($sessionId);
            $this->state = $this->createNewSession($request);
            $this->initialized = true;
            return $this->state;
        }

        // Refresh binding data for the current request (e.g. rotating hash salts)
        $this->bindingData = $this->buildBindingData($request);

        // Create session state with clean user data only
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

        // Write data to new ID
        $serializedData = $this->serializeData($this->prepareDataForStorage($this->state));
        $this->storage->write($this->state->getId(), $serializedData, $this->absoluteTimeout);

        if ($this->regenerateGracePeriod > 0) {
            // Leave only a tombstone under the old ID for the grace window.
            // It never carries session data or authentication: a request with
            // the old cookie gets a fresh, empty session.
            $tombstone = $this->serializeData([self::TOMBSTONE_KEY => time()]);
            $this->storage->write($oldId, $tombstone, $this->regenerateGracePeriod);
        } else {
            // Destroy old session immediately (recommended default).
            $this->storage->destroy($oldId);
        }
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
     * Create a fresh session state and initialise binding data from the request.
     * All new-session paths go through this method so binding is always seeded.
     */
    private function createNewSession(ServerRequestInterface $request): SessionState
    {
        $this->bindingData = $this->buildBindingData($request);
        $state = new SessionState(SessionId::generate());

        // Binding data is stored separately (see prepareDataForStorage) so it
        // does not pollute the user-visible data API, but it must be written on
        // first commit so the next request can verify it.
        // We still mark the state dirty so commit() persists it.
        return $state->withMergedData([]); // marks dirty
    }

    /**
     * Prepare session data for storage, including metadata and binding data.
     * Binding keys are intentionally kept out of the public SessionState data
     * array and injected here so they are always persisted.
     */
    private function prepareDataForStorage(SessionState $state): array
    {
        $data = $state->getData();

        // Internal metadata
        $data['_created_at'] = $state->getCreatedAt()->format('c');
        $data['_last_activity_at'] = $state->getLastActivityAt()->format('c');

        // Binding data (may be empty when binding is disabled)
        foreach ($this->bindingData as $key => $value) {
            $data[$key] = $value;
        }

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
     * Unserialize session data from storage.
     * Restricts deserialization to plain arrays/scalars to prevent
     * PHP object injection (POP gadget chain) attacks.
     */
    private function unserializeData(string $data): array
    {
        $unserialized = unserialize($data, ['allowed_classes' => false]);
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
     * Build the binding data array from the current request.
     *
     * @return array<string, string>
     */
    private function buildBindingData(ServerRequestInterface $request): array
    {
        $binding = [];

        if ($this->bindToUserAgent) {
            $binding[self::USER_AGENT_KEY] = $request->getHeaderLine('User-Agent');
        }

        if ($this->bindToIp) {
            $ip = SessionSecurity::getClientIp($request->getServerParams());
            $binding[self::IP_HASH_KEY] = SessionSecurity::hashIpAddress($ip);
        }

        return $binding;
    }

    /**
     * Extract stored binding keys from the raw session data array.
     *
     * @param array $sessionData Raw deserialized session data
     * @return array<string, string>
     */
    private function extractBindingData(array $sessionData): array
    {
        $binding = [];

        if (isset($sessionData[self::USER_AGENT_KEY])) {
            $binding[self::USER_AGENT_KEY] = $sessionData[self::USER_AGENT_KEY];
        }

        if (isset($sessionData[self::IP_HASH_KEY])) {
            $binding[self::IP_HASH_KEY] = $sessionData[self::IP_HASH_KEY];
        }

        return $binding;
    }

    /**
     * Verify session binding against stored client characteristics.
     *
     * @param array<string, string> $storedBinding Previously stored binding data
     */
    private function verifySessionBinding(ServerRequestInterface $request, array $storedBinding): bool
    {
        if ($this->bindToUserAgent && isset($storedBinding[self::USER_AGENT_KEY])) {
            $currentUserAgent = $request->getHeaderLine('User-Agent');
            if (!hash_equals($storedBinding[self::USER_AGENT_KEY], $currentUserAgent)) {
                return false;
            }
        }

        if ($this->bindToIp && isset($storedBinding[self::IP_HASH_KEY])) {
            $currentIp = SessionSecurity::getClientIp($request->getServerParams());
            $currentIpHash = SessionSecurity::hashIpAddress($currentIp);
            if (!hash_equals($storedBinding[self::IP_HASH_KEY], $currentIpHash)) {
                return false;
            }
        }

        return true;
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

