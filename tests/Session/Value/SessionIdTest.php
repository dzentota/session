<?php

declare(strict_types=1);

namespace Dzentota\Session\Tests\Session\Value;

use Dzentota\Session\Exception\InvalidSessionIdException;
use Dzentota\Session\Value\SessionId;
use PHPUnit\Framework\TestCase;

class SessionIdTest extends TestCase
{
    public function testGenerate(): void
    {
        $sessionId = SessionId::generate();
        $this->assertInstanceOf(SessionId::class, $sessionId);

        // Check ID format (UUID v4)
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $sessionId->toNative()
        );
    }

    public function testFromNative(): void
    {
        $uuidString = '123e4567-e89b-12d3-a456-426614174000';
        $sessionId = SessionId::fromNative($uuidString);

        $this->assertInstanceOf(SessionId::class, $sessionId);
        $this->assertEquals($uuidString, $sessionId->toNative());
    }

    public function testFromNativeWithInvalidUuid(): void
    {
        $this->expectException(InvalidSessionIdException::class);
        SessionId::fromNative('invalid-uuid');
    }

    public function testEquals(): void
    {
        $uuidString = '123e4567-e89b-12d3-a456-426614174000';
        $sessionId1 = SessionId::fromNative($uuidString);
        $sessionId2 = SessionId::fromNative($uuidString);
        $sessionId3 = SessionId::generate();

        $this->assertTrue($sessionId1->equals($sessionId2));
        $this->assertFalse($sessionId1->equals($sessionId3));
    }

    public function testToString(): void
    {
        $uuidString = '123e4567-e89b-12d3-a456-426614174000';
        $sessionId = SessionId::fromNative($uuidString);

        $this->assertEquals($uuidString, $sessionId->toNative());
        $this->assertEquals($uuidString, (string)$sessionId);
    }
}
