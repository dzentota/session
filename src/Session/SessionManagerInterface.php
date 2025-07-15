<?php

declare(strict_types=1);

namespace Dzentota\Session;

use Dzentota\Session\Value\CsrfToken;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Interface for session manager implementations
 */
interface SessionManagerInterface
{
    /**
     * Start or resume a session from the request
     *
     * @param ServerRequestInterface $request The incoming request
     * @return SessionState The initial session state
     */
    public function start(ServerRequestInterface $request): SessionState;

    /**
     * Get a value from the session data
     *
     * @param string $key The key to retrieve
     * @param mixed $default Default value if key doesn't exist
     * @return mixed The value or default
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Set a value in the session data
     *
     * @param string $key The key to set
     * @param mixed $value The value to store
     */
    public function set(string $key, mixed $value): void;

    /**
     * Remove a value from the session data
     *
     * @param string $key The key to remove
     */
    public function remove(string $key): void;

    /**
     * Regenerate the session ID securely
     * This should be called after authentication or privilege change
     */
    public function regenerateId(): void;

    /**
     * Clear all data from the session (keeps the session alive)
     */
    public function clear(): void;

    /**
     * Completely destroy the session and all its data
     */
    public function destroy(): void;

    /**
     * Get the current session state
     *
     * @return SessionState The current session state object
     */
    public function getState(): SessionState;

    /**
     * Generate a new CSRF token and store its hash in the session
     *
     * @return CsrfToken The generated token
     */
    public function generateCsrfToken(): CsrfToken;

    /**
     * Validate a submitted CSRF token against the stored hash
     *
     * @param string $submittedToken The raw token from the request
     * @return bool True if the token is valid
     */
    public function isCsrfTokenValid(string $submittedToken): bool;

    /**
     * Commit session changes to storage and add cookie to response
     *
     * @param ResponseInterface $response The response to modify
     * @return ResponseInterface The modified response
     */
    public function commit(ResponseInterface $response): ResponseInterface;
}
