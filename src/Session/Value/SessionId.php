<?php

declare(strict_types=1);

namespace Dzentota\Session\Value;

use Dzentota\Session\Exception\InvalidSessionIdException;
use Ramsey\Uuid\Uuid;

/**
 * Value object representing a secure session identifier
 */
final class SessionId
{
    /**
     * The raw string representation of the session ID
     */
    private string $value;

    /**
     * Private constructor to enforce using factory methods
     */
    private function __construct(string $value)
    {
        $this->value = $value;
    }

    /**
     * Generate a new cryptographically secure random session ID
     */
    public static function generate(): self
    {
        // Use UUID v4 for high entropy (122 bits) and standard format
        $uuid = Uuid::uuid4()->toString();
        return new self($uuid);
    }

    /**
     * Create a SessionId from an existing string, with validation
     *
     * @throws InvalidSessionIdException If the provided string is not a valid session ID
     */
    public static function fromNative(string $value): self
    {
        // Validate the session ID format (UUID v4)
        if (!Uuid::isValid($value)) {
            throw new InvalidSessionIdException('Invalid session ID format');
        }

        return new self($value);
    }

    /**
     * Get the string representation of the session ID
     */
    public function toNative(): string
    {
        return $this->value;
    }

    /**
     * Compare with another SessionId
     */
    public function equals(SessionId $other): bool
    {
        return hash_equals($this->value, $other->value);
    }

    /**
     * String representation for debugging
     */
    public function __toString(): string
    {
        return $this->value;
    }
}
