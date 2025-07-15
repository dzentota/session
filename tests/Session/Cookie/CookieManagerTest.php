<?php

declare(strict_types=1);

namespace Dzentota\Session\Tests\Session\Cookie;

use Dzentota\Session\Cookie\CookieManager;
use Dzentota\Session\SessionState;
use Dzentota\Session\Value\SessionId;
use PHPUnit\Framework\TestCase;

class CookieManagerTest extends TestCase
{
    private CookieManager $cookieManager;
    private SessionId $sessionId;
    private SessionState $sessionState;

    protected function setUp(): void
    {
        $this->cookieManager = new CookieManager(
            name: 'session',
            secure: true,
            httpOnly: true,
            sameSite: 'Lax',
            path: '/',
            lifetime: 3600
        );

        $this->sessionId = SessionId::generate();
        $this->sessionState = new SessionState($this->sessionId);
    }

    public function testGetCookieName(): void
    {
        $this->assertEquals('session', $this->cookieManager->getCookieName());
    }

    public function testGetCookieHeaderWithActiveSession(): void
    {
        $cookieHeader = $this->cookieManager->getCookieHeader($this->sessionState);

        $this->assertNotNull($cookieHeader);
        $this->assertStringContainsString('session=' . urlencode($this->sessionId->toNative()), $cookieHeader);
        $this->assertStringContainsString('Path=/', $cookieHeader);
        $this->assertStringContainsString('HttpOnly', $cookieHeader);
        $this->assertStringContainsString('Secure', $cookieHeader);
        $this->assertStringContainsString('SameSite=Lax', $cookieHeader);
        $this->assertStringContainsString('Max-Age=3600', $cookieHeader);
    }

    public function testGetCookieHeaderWithDestroyedSession(): void
    {
        $destroyedState = $this->sessionState->withDestroyed();
        $cookieHeader = $this->cookieManager->getCookieHeader($destroyedState);

        $this->assertNotNull($cookieHeader);
        $this->assertStringContainsString('session=' . urlencode($this->sessionId->toNative()), $cookieHeader);
        $this->assertStringContainsString('Expires=Thu, 01 Jan 1970 00:00:00 GMT', $cookieHeader);
        $this->assertStringContainsString('Max-Age=0', $cookieHeader);
    }

    public function testCookieManagerWithHostPrefix(): void
    {
        $hostPrefixCookieManager = new CookieManager(
            name: '__Host-session',
            secure: false, // Attempt to make it non-secure, but should be ignored
            httpOnly: true,
            sameSite: 'Lax'
        );

        $cookieHeader = $hostPrefixCookieManager->getCookieHeader($this->sessionState);

        $this->assertStringContainsString('__Host-session=', $cookieHeader);
        $this->assertStringContainsString('Secure', $cookieHeader); // Should be Secure despite setting false
    }

    public function testCookieManagerWithSameSiteNone(): void
    {
        $sameSiteNoneCookieManager = new CookieManager(
            name: 'session',
            secure: false, // Attempt to make it non-secure, but should be ignored
            httpOnly: true,
            sameSite: 'None'
        );

        $cookieHeader = $sameSiteNoneCookieManager->getCookieHeader($this->sessionState);

        $this->assertStringContainsString('SameSite=None', $cookieHeader);
        $this->assertStringContainsString('Secure', $cookieHeader); // Should be Secure despite setting false
    }

    public function testCookieManagerWithoutLifetime(): void
    {
        $sessionCookieManager = new CookieManager(
            name: 'session',
            secure: true,
            httpOnly: true,
            sameSite: 'Strict',
            lifetime: null // Session cookie
        );

        $cookieHeader = $sessionCookieManager->getCookieHeader($this->sessionState);

        $this->assertStringNotContainsString('Max-Age=', $cookieHeader);
    }

    public function testCookieManagerWithCustomPath(): void
    {
        $customPathCookieManager = new CookieManager(
            name: 'session',
            secure: true,
            httpOnly: true,
            sameSite: 'Lax',
            path: '/app'
        );

        $cookieHeader = $customPathCookieManager->getCookieHeader($this->sessionState);

        $this->assertStringContainsString('Path=/app', $cookieHeader);
    }
}
