<?php

declare(strict_types=1);

namespace Dzentota\Session;

use Dzentota\Session\Value\SessionId;
use DateTimeImmutable;

/**
 * Immutable value object representing the state of a session
 */
final class SessionState
{
    /**
     * Status constants for the session
     */
    public const STATUS_ACTIVE = 'active';
    public const STATUS_REGENERATED = 'regenerated';
    public const STATUS_DESTROYED = 'destroyed';

    /**
     * @param SessionId $id The session identifier
     * @param array $data The session data
     * @param DateTimeImmutable $createdAt When the session was created
     * @param DateTimeImmutable $lastActivityAt When the session was last accessed
     * @param string $status The current status of the session
     * @param bool $dirty Whether the session has been modified since last save
     */
    public function __construct(
        private readonly SessionId         $id,
        private readonly array             $data = [],
        private readonly DateTimeImmutable $createdAt = new DateTimeImmutable(),
        private readonly DateTimeImmutable $lastActivityAt = new DateTimeImmutable(),
        private readonly string            $status = self::STATUS_ACTIVE,
        private readonly bool $dirty = false
    ) {
    }

    /**
     * Get the session ID
     */
    public function getId(): SessionId
    {
        return $this->id;
    }

    /**
     * Get all session data
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * Get a specific session value
     *
     * @param string $key The key to retrieve
     * @param mixed $default Default value if key doesn't exist
     * @return mixed The value or default
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * Check if a key exists in session data
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    /**
     * Create a new state with an updated value
     */
    public function with(string $key, mixed $value): self
    {
        $data = $this->data;
        $data[$key] = $value;

        return new self(
            $this->id,
            $data,
            $this->createdAt,
            $this->lastActivityAt,
            $this->status,
            true // Mark as dirty
        );
    }

    /**
     * Create a new state with a value removed
     */
    public function without(string $key): self
    {
        if (!$this->has($key)) {
            return $this;
        }

        $data = $this->data;
        unset($data[$key]);

        return new self(
            $this->id,
            $data,
            $this->createdAt,
            $this->lastActivityAt,
            $this->status,
            true // Mark as dirty
        );
    }

    /**
     * Create a new state with all data cleared
     */
    public function withClearedData(): self
    {
        return new self(
            $this->id,
            [],
            $this->createdAt,
            $this->lastActivityAt,
            $this->status,
            true // Mark as dirty
        );
    }

    /**
     * Create a new state with a regenerated ID
     */
    public function withRegeneratedId(): self
    {
        return new self(
            SessionId::generate(),
            $this->data,
            $this->createdAt,
            $this->lastActivityAt,
            self::STATUS_REGENERATED,
            true // Mark as dirty
        );
    }

    /**
     * Create a new state marked as destroyed
     */
    public function withDestroyed(): self
    {
        return new self(
            $this->id,
            [],
            $this->createdAt,
            $this->lastActivityAt,
            self::STATUS_DESTROYED,
            true // Mark as dirty
        );
    }

    /**
     * Create a new state with updated last activity time
     */
    public function withRefreshedActivity(): self
    {
        return new self(
            $this->id,
            $this->data,
            $this->createdAt,
            new DateTimeImmutable(),
            $this->status,
            $this->dirty
        );
    }

    /**
     * Get the session creation timestamp
     */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Get the last activity timestamp
     */
    public function getLastActivityAt(): DateTimeImmutable
    {
        return $this->lastActivityAt;
    }

    /**
     * Get the current session status
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * Check if the session state has been modified
     */
    public function isDirty(): bool
    {
        return $this->dirty;
    }

    /**
     * Create a new identical state, but marked as clean (saved)
     */
    public function withCleanState(): self
    {
        if (!$this->dirty) {
            return $this;
        }

        return new self(
            $this->id,
            $this->data,
            $this->createdAt,
            $this->lastActivityAt,
            $this->status,
            false
        );
    }
}
