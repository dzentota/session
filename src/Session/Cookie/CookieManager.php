<?php

declare(strict_types=1);

namespace Dzentota\Session\Cookie;

use Dzentota\Session\SessionState;

/**
 * Default implementation of the cookie manager with secure defaults
 */
class CookieManager implements CookieManagerInterface
{
    /**
     * @param string $name Cookie name with secure __Host- prefix
     * @param bool $secure Whether the cookie should only be sent over HTTPS
     * @param bool $httpOnly Whether the cookie should be accessible only through HTTP protocol
     * @param string $sameSite The SameSite policy (Strict, Lax, None)
     * @param string $path Cookie path
     * @param int|null $lifetime Cookie lifetime in seconds (null = session cookie)
     */
    public function __construct(
        private string $name = '__Host-id',
        private bool $secure = true,
        private bool $httpOnly = true,
        private string $sameSite = 'Strict',
        private string $path = '/',
        private ?int $lifetime = null
    ) {
        // Validate the cookie name - if using __Host- prefix, secure must be true and no domain allowed
        if (str_starts_with($this->name, '__Host-')) {
            $this->secure = true;
            // No domain allowed for __Host- prefixed cookies
        }

        // If SameSite=None, Secure must be true
        if (strtolower($this->sameSite) === 'none') {
            $this->secure = true;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getCookieName(): string
    {
        return $this->name;
    }

    /**
     * {@inheritDoc}
     */
    public function getCookieHeader(SessionState $state): ?string
    {
        // Don't send a cookie for destroyed sessions unless we need to expire it
        if ($state->getStatus() === SessionState::STATUS_DESTROYED) {
            return $this->getExpiredCookieHeader($state->getId()->toNative());
        }

        $value = $state->getId()->toNative();

        $parts = [
            $this->name . '=' . urlencode($value),
            'Path=' . $this->path
        ];

        // Add security flags
        if ($this->secure) {
            $parts[] = 'Secure';
        }

        if ($this->httpOnly) {
            $parts[] = 'HttpOnly';
        }

        $parts[] = 'SameSite=' . $this->sameSite;

        // Add Max-Age for persistent cookies
        if ($this->lifetime !== null && $this->lifetime > 0) {
            $parts[] = 'Max-Age=' . $this->lifetime;
        }

        return implode('; ', $parts);
    }

    /**
     * Get a Set-Cookie header that will expire the cookie
     */
    private function getExpiredCookieHeader(string $value): string
    {
        $parts = [
            $this->name . '=' . urlencode($value),
            'Path=' . $this->path,
            'Expires=Thu, 01 Jan 1970 00:00:00 GMT',
            'Max-Age=0'
        ];

        // Add security flags
        if ($this->secure) {
            $parts[] = 'Secure';
        }

        if ($this->httpOnly) {
            $parts[] = 'HttpOnly';
        }

        $parts[] = 'SameSite=' . $this->sameSite;

        return implode('; ', $parts);
    }
}
