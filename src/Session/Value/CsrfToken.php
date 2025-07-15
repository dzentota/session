<?php

declare(strict_types=1);

namespace Dzentota\Session\Value;

use Dzentota\Session\Exception\InvalidTokenException;

/**
 * Value object representing a CSRF token
 */
final class CsrfToken
{
    private string $value;

    /**
     * Private constructor to enforce using factory methods
     */
    private function __construct(string $value)
    {
        $this->value = $value;
    }

    /**
     * Generate a new cryptographically secure CSRF token
     */
    public static function generate(): self
    {
        return new self(bin2hex(random_bytes(32))); // 256 bits of entropy
    }

    /**
     * Create a CsrfToken from an existing string, with validation
     *
     * @throws InvalidTokenException If the provided string is not a valid CSRF token
     */
    public static function fromNative(string $value): self
    {
        // Basic validation - CSRF token should be a 64-character hex string (256 bits)
        if (!preg_match('/^[0-9a-f]{64}$/i', $value)) {
            throw new InvalidTokenException('Invalid CSRF token format');
        }

        return new self($value);
    }

    /**
     * Get the string representation of the token
     */
    public function toNative(): string
    {
        return $this->value;
    }

    /**
     * Compare with another CsrfToken
     */
    public function equals(CsrfToken $other): bool
    {
        return hash_equals($this->value, $other->value);
    }

    /**
     * Get the hash of the token for secure storage
     */
    public function getHash(): string
    {
        return hash('sha256', $this->value);
    }

    /**
     * String representation for debugging
     */
    public function __toString(): string
    {
        return $this->value;
    }
}
