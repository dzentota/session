<?php

declare(strict_types=1);

namespace Dzentota\Session\Tests\Session\Exception;

use Dzentota\Session\Exception\InvalidSessionIdException;
use Dzentota\Session\Exception\InvalidTokenException;
use PHPUnit\Framework\TestCase;
use Dzentota\Session\Value\SessionId;
use Dzentota\Session\Value\CsrfToken;

class ExceptionsTest extends TestCase
{
    public function testInvalidSessionIdException(): void
    {
        // Test that exception can be instantiated
        $exception = new InvalidSessionIdException('Invalid session ID');
        $this->assertInstanceOf(InvalidSessionIdException::class, $exception);
        $this->assertInstanceOf(\InvalidArgumentException::class, $exception);
        $this->assertEquals('Invalid session ID', $exception->getMessage());

        // Test that it's thrown by SessionId when appropriate
        $this->expectException(InvalidSessionIdException::class);
        $this->expectExceptionMessage('Invalid session ID format');
        SessionId::fromNative('invalid-session-id');
    }

    public function testInvalidTokenException(): void
    {
        // Test that exception can be instantiated
        $exception = new InvalidTokenException('Invalid CSRF token');
        $this->assertInstanceOf(InvalidTokenException::class, $exception);
        $this->assertInstanceOf(\InvalidArgumentException::class, $exception);
        $this->assertEquals('Invalid CSRF token', $exception->getMessage());

        // Test that it's thrown by CsrfToken when appropriate
        $this->expectException(InvalidTokenException::class);
        $this->expectExceptionMessage('Invalid CSRF token format');
        CsrfToken::fromNative('invalid-token');
    }

    public function testExceptionsAreUsedConsistently(): void
    {
        // Test that SessionId consistently uses InvalidSessionIdException
        try {
            SessionId::fromNative('invalid-uuid-format');
            $this->fail('InvalidSessionIdException was not thrown');
        } catch (InvalidSessionIdException $e) {
            $this->assertStringContainsString('Invalid session ID format', $e->getMessage());
        }

        // Test that CsrfToken consistently uses InvalidTokenException
        try {
            CsrfToken::fromNative('invalid-token-format');
            $this->fail('InvalidTokenException was not thrown');
        } catch (InvalidTokenException $e) {
            $this->assertStringContainsString('Invalid CSRF token format', $e->getMessage());
        }
    }
}
