<?php

declare(strict_types=1);

namespace Dzentota\Session\Tests\Session\Support;

use Dzentota\Session\Storage\SessionStorageInterface;
use Dzentota\Session\Value\SessionId;

/** In-memory storage used for behavioural tests. */
final class ArrayStorage implements SessionStorageInterface
{
    /** @var array<string, string> */
    public array $data = [];

    public function read(SessionId $sessionId): ?string
    {
        return $this->data[$sessionId->toNative()] ?? null;
    }

    public function write(SessionId $sessionId, string $data, int $lifetime): bool
    {
        $this->data[$sessionId->toNative()] = $data;
        return true;
    }

    public function destroy(SessionId $sessionId): bool
    {
        unset($this->data[$sessionId->toNative()]);
        return true;
    }

    public function gc(int $maxLifetime): bool
    {
        return true;
    }
}
