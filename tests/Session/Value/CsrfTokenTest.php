<?php

declare(strict_types=1);

namespace Dzentota\Session\Tests\Session\Value;

use Dzentota\Session\Exception\InvalidTokenException;
use Dzentota\Session\Value\CsrfToken;
use PHPUnit\Framework\TestCase;

class CsrfTokenTest extends TestCase
{
    public function testGenerate(): void
    {
        $token = CsrfToken::generate();
        $this->assertInstanceOf(CsrfToken::class, $token);

        // Check token format (64-character hex string)
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/i', $token->toNative());
    }

    public function testFromNative(): void
    {
        $tokenString = str_repeat('a', 64); // Valid 64-character hex token
        $token = CsrfToken::fromNative($tokenString);

        $this->assertInstanceOf(CsrfToken::class, $token);
        $this->assertEquals($tokenString, $token->toNative());
    }

    public function testFromNativeWithInvalidToken(): void
    {
        $this->expectException(InvalidTokenException::class);
        CsrfToken::fromNative('invalid-token');
    }

    public function testFromNativeWithShortToken(): void
    {
        $this->expectException(InvalidTokenException::class);
        CsrfToken::fromNative('abc123'); // Token too short
    }

    public function testEquals(): void
    {
        $tokenString = str_repeat('a', 64);
        $token1 = CsrfToken::fromNative($tokenString);
        $token2 = CsrfToken::fromNative($tokenString);
        $token3 = CsrfToken::generate();

        $this->assertTrue($token1->equals($token2));
        $this->assertFalse($token1->equals($token3));
    }

    public function testGetHash(): void
    {
        $token = CsrfToken::generate();
        $hash = $token->getHash();

        // Hash should be a 64-character string (sha256)
        $this->assertIsString($hash);
        $this->assertEquals(64, strlen($hash));

        // Identical tokens should produce identical hashes
        $tokenString = str_repeat('a', 64);
        $token1 = CsrfToken::fromNative($tokenString);
        $token2 = CsrfToken::fromNative($tokenString);
        $this->assertEquals($token1->getHash(), $token2->getHash());
    }

    public function testToString(): void
    {
        $tokenString = str_repeat('a', 64);
        $token = CsrfToken::fromNative($tokenString);

        $this->assertEquals($tokenString, $token->toNative());
        $this->assertEquals($tokenString, (string)$token);
    }
}
