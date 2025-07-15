<?php

declare(strict_types=1);

namespace Dzentota\Session\Tests\Session;

use DateTimeImmutable;
use Dzentota\Session\Cookie\CookieManagerInterface;
use Dzentota\Session\SessionManager;
use Dzentota\Session\SessionState;
use Dzentota\Session\Storage\SessionStorageInterface;
use Dzentota\Session\Value\CsrfToken;
use Dzentota\Session\Value\SessionId;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class SessionManagerTest extends TestCase
{
    private SessionStorageInterface $storage;
    private CookieManagerInterface $cookieManager;
    private SessionManager $sessionManager;
    private ServerRequestInterface $request;
    private ResponseInterface $response;

    protected function setUp(): void
    {
        $this->storage = $this->createMock(SessionStorageInterface::class);
        $this->cookieManager = $this->createMock(CookieManagerInterface::class);
        $this->request = $this->createMock(ServerRequestInterface::class);
        $this->response = $this->createMock(ResponseInterface::class);

        // Create session manager with default settings
        $this->sessionManager = new SessionManager(
            $this->storage,
            $this->cookieManager
        );
    }

    public function testStartWithNoCookieCreatesNewSession(): void
    {
        // Configure mock for request without cookies
        $this->request->method('getCookieParams')->willReturn([]);
        $this->cookieManager->method('getCookieName')->willReturn('session');

        // Call method
        $state = $this->sessionManager->start($this->request);

        // Check that the returned state contains a new session ID
        $this->assertInstanceOf(SessionState::class, $state);
        $this->assertInstanceOf(SessionId::class, $state->getId());
    }

    public function testStartWithValidCookieLoadsSession(): void
    {
        // Create a valid session ID
        $sessionId = SessionId::generate();
        $sessionData = [
            '_created_at' => (new DateTimeImmutable())->format('c'),
            '_last_activity_at' => (new DateTimeImmutable())->format('c'),
            'test_key' => 'test_value'
        ];
        $serializedData = serialize($sessionData);

        // Configure mocks
        $this->cookieManager->method('getCookieName')->willReturn('session');
        $this->request->method('getCookieParams')->willReturn(['session' => $sessionId->toNative()]);
        $this->storage->method('read')->with($sessionId)->willReturn($serializedData);
        $this->request->method('getHeaderLine')->with('User-Agent')->willReturn('TestUserAgent');
        $this->request->method('getServerParams')->willReturn(['REMOTE_ADDR' => '127.0.0.1']);

        // Call method
        $state = $this->sessionManager->start($this->request);

        // Check that the session is loaded correctly
        $this->assertInstanceOf(SessionState::class, $state);
        $this->assertEquals($sessionId, $state->getId());
        $this->assertEquals('test_value', $state->get('test_key'));
    }

    public function testGetSetRemoveOperations(): void
    {
        // Configure mock for request without cookies to create a new session
        $this->request->method('getCookieParams')->willReturn([]);
        $this->cookieManager->method('getCookieName')->willReturn('session');

        // Initialize session
        $this->sessionManager->start($this->request);

        // Test data operations
        $this->sessionManager->set('test_key', 'test_value');
        $this->assertEquals('test_value', $this->sessionManager->get('test_key'));

        $this->sessionManager->set('another_key', ['nested' => 'data']);
        $this->assertEquals(['nested' => 'data'], $this->sessionManager->get('another_key'));

        $this->sessionManager->remove('test_key');
        $this->assertNull($this->sessionManager->get('test_key'));

        // Check default value
        $this->assertEquals('default', $this->sessionManager->get('non_existent', 'default'));
    }

    public function testRegenerateId(): void
    {
        // Configure mock for request without cookies
        $this->request->method('getCookieParams')->willReturn([]);
        $this->cookieManager->method('getCookieName')->willReturn('session');

        // Initialize session
        $this->sessionManager->start($this->request);

        // Save data to session
        $this->sessionManager->set('test_key', 'test_value');

        // Get current ID
        $oldId = $this->sessionManager->getState()->getId();

        // Configure storage mock to check write operation
        $this->storage->expects($this->atLeast(2))
                      ->method('write')
                      ->willReturn(true);

        // Regenerate ID
        $this->sessionManager->regenerateId();

        // Check that the ID has changed
        $newId = $this->sessionManager->getState()->getId();
        $this->assertNotEquals($oldId, $newId);

        // Check that the data is preserved
        $this->assertEquals('test_value', $this->sessionManager->get('test_key'));
    }

    public function testCsrfTokenGeneration(): void
    {
        // Configure mock for request without cookies
        $this->request->method('getCookieParams')->willReturn([]);
        $this->cookieManager->method('getCookieName')->willReturn('session');

        // Initialize session
        $this->sessionManager->start($this->request);

        // Generate CSRF token
        $token = $this->sessionManager->generateCsrfToken();

        // Check that the token is created correctly
        $this->assertInstanceOf(CsrfToken::class, $token);

        // Check token validation
        $this->assertTrue($this->sessionManager->isCsrfTokenValid($token->toNative()));
        $this->assertFalse($this->sessionManager->isCsrfTokenValid('invalid-token'));
    }

    public function testClearDestroyCycle(): void
    {
        // Configure mock for request without cookies
        $this->request->method('getCookieParams')->willReturn([]);
        $this->cookieManager->method('getCookieName')->willReturn('session');

        // Initialize session
        $this->sessionManager->start($this->request);

        // Save data
        $this->sessionManager->set('test_key', 'test_value');
        $this->assertEquals('test_value', $this->sessionManager->get('test_key'));

        // Clear session
        $this->sessionManager->clear();
        $this->assertNull($this->sessionManager->get('test_key'));

        // Check that the session ID has not changed after clearing
        $idAfterClear = $this->sessionManager->getState()->getId();

        // Set expectation for destroy call
        $this->storage->expects($this->once())
                      ->method('destroy')
                      ->with($idAfterClear);

        // Destroy session
        $this->sessionManager->destroy();
    }

    public function testCommitWritesToStorageAndAddsResponseCookie(): void
    {
        // Configure mock for request without cookies
        $this->request->method('getCookieParams')->willReturn([]);
        $this->cookieManager->method('getCookieName')->willReturn('session');

        // Initialize session
        $this->sessionManager->start($this->request);

        // Modify session data to dirty the state
        $this->sessionManager->set('test_key', 'test_value');

        // Configure expectations for storage and cookie manager
        $this->storage->expects($this->once())
                      ->method('write')
                      ->willReturn(true);

        $this->cookieManager->expects($this->once())
                           ->method('getCookieHeader')
                           ->willReturn('session=123456; Path=/; HttpOnly; Secure; SameSite=Lax');

        $this->response->expects($this->once())
                      ->method('withHeader')
                      ->with('Set-Cookie', 'session=123456; Path=/; HttpOnly; Secure; SameSite=Lax')
                      ->willReturnSelf();

        // Commit session
        $this->sessionManager->commit($this->response);
    }
}
