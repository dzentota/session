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
        $this->request->method('getHeaderLine')->with('User-Agent')->willReturn('TestAgent');
        $this->request->method('getServerParams')->willReturn(['REMOTE_ADDR' => '127.0.0.1']);

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
        $this->request->method('getHeaderLine')->with('User-Agent')->willReturn('TestAgent');
        $this->request->method('getServerParams')->willReturn(['REMOTE_ADDR' => '127.0.0.1']);

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
        $this->request->method('getHeaderLine')->with('User-Agent')->willReturn('TestAgent');
        $this->request->method('getServerParams')->willReturn(['REMOTE_ADDR' => '127.0.0.1']);

        // Initialize session
        $this->sessionManager->start($this->request);

        // Save data to session
        $this->sessionManager->set('test_key', 'test_value');

        // Get current ID
        $oldId = $this->sessionManager->getState()->getId();

        // Configure storage mock to check write operation
        $this->storage->expects($this->atLeast(1))
                      ->method('write')
                      ->willReturn(true);

        // Default: old session should be destroyed immediately (grace period = 0)
        $this->storage->expects($this->once())
                      ->method('destroy');

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
        $this->request->method('getHeaderLine')->with('User-Agent')->willReturn('TestAgent');
        $this->request->method('getServerParams')->willReturn(['REMOTE_ADDR' => '127.0.0.1']);

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
        $this->request->method('getHeaderLine')->with('User-Agent')->willReturn('TestAgent');
        $this->request->method('getServerParams')->willReturn(['REMOTE_ADDR' => '127.0.0.1']);

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
        $this->request->method('getHeaderLine')->with('User-Agent')->willReturn('TestAgent');
        $this->request->method('getServerParams')->willReturn(['REMOTE_ADDR' => '127.0.0.1']);

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

    public function testBindingDataIsPersistedForNewSession(): void
    {
        $this->request->method('getCookieParams')->willReturn([]);
        $this->cookieManager->method('getCookieName')->willReturn('session');
        $this->request->method('getHeaderLine')->with('User-Agent')->willReturn('MyBrowser/1.0');
        $this->request->method('getServerParams')->willReturn(['REMOTE_ADDR' => '10.0.0.1']);

        $this->sessionManager->start($this->request);
        $this->sessionManager->set('foo', 'bar');

        // Capture what is written to storage
        $writtenData = null;
        $this->storage->expects($this->once())
                      ->method('write')
                      ->willReturnCallback(function ($id, $serialized) use (&$writtenData) {
                          $writtenData = unserialize($serialized, ['allowed_classes' => false]);
                          return true;
                      });

        $this->cookieManager->method('getCookieHeader')->willReturn('session=x');
        $this->response->method('withHeader')->willReturnSelf();
        $this->sessionManager->commit($this->response);

        $this->assertIsArray($writtenData);
        // Binding keys must be in the stored payload
        $this->assertArrayHasKey('_user_agent', $writtenData);
        $this->assertArrayHasKey('_ip_hash', $writtenData);
        $this->assertEquals('MyBrowser/1.0', $writtenData['_user_agent']);
        // User data must also be present
        $this->assertEquals('bar', $writtenData['foo']);
        // Internal metadata timestamps must be present
        $this->assertArrayHasKey('_created_at', $writtenData);
        $this->assertArrayHasKey('_last_activity_at', $writtenData);
    }

    public function testBindingDataNotLeakedIntoUserFacingApi(): void
    {
        $this->request->method('getCookieParams')->willReturn([]);
        $this->cookieManager->method('getCookieName')->willReturn('session');
        $this->request->method('getHeaderLine')->with('User-Agent')->willReturn('MyBrowser/1.0');
        $this->request->method('getServerParams')->willReturn(['REMOTE_ADDR' => '10.0.0.1']);

        $this->sessionManager->start($this->request);

        // Internal binding keys must NOT be visible via get() or getData()
        $this->assertNull($this->sessionManager->get('_user_agent'));
        $this->assertNull($this->sessionManager->get('_ip_hash'));
        $this->assertArrayNotHasKey('_user_agent', $this->sessionManager->getState()->getData());
        $this->assertArrayNotHasKey('_ip_hash', $this->sessionManager->getState()->getData());
    }

    public function testBindingMismatchCreatesNewSession(): void
    {
        $sessionId = SessionId::generate();
        $ipHash = \Dzentota\Session\SessionSecurity::hashIpAddress('192.168.1.1');
        $sessionData = [
            '_created_at'       => (new DateTimeImmutable())->format('c'),
            '_last_activity_at' => (new DateTimeImmutable())->format('c'),
            '_user_agent'       => 'LegitBrowser/1.0',
            '_ip_hash'          => $ipHash,
            'user_id'           => 42,
        ];

        $this->cookieManager->method('getCookieName')->willReturn('session');
        $this->request->method('getCookieParams')
                      ->willReturn(['session' => $sessionId->toNative()]);
        $this->storage->method('read')->willReturn(serialize($sessionData));
        // Different User-Agent → binding mismatch
        $this->request->method('getHeaderLine')->with('User-Agent')->willReturn('AttackerBrowser/99');
        $this->request->method('getServerParams')->willReturn(['REMOTE_ADDR' => '192.168.1.1']);

        $this->storage->expects($this->once())->method('destroy');

        $state = $this->sessionManager->start($this->request);

        // A new session must have been issued
        $this->assertNotEquals($sessionId, $state->getId());
        $this->assertNull($this->sessionManager->get('user_id'));
    }

    public function testRegenerateIdWithGracePeriodWritesTwice(): void
    {
        $manager = new SessionManager(
            $this->storage,
            $this->cookieManager,
            regenerateGracePeriod: 5
        );

        $this->request->method('getCookieParams')->willReturn([]);
        $this->cookieManager->method('getCookieName')->willReturn('session');
        $this->request->method('getHeaderLine')->with('User-Agent')->willReturn('TestAgent');
        $this->request->method('getServerParams')->willReturn(['REMOTE_ADDR' => '127.0.0.1']);

        $manager->start($this->request);
        $manager->set('k', 'v');

        // With grace period > 0, write() must be called twice (new ID + old ID),
        // and destroy() must NOT be called.
        $this->storage->expects($this->exactly(2))->method('write')->willReturn(true);
        $this->storage->expects($this->never())->method('destroy');

        $manager->regenerateId();
    }

    public function testRegenerateIdWithoutGracePeriodDestroysOldId(): void
    {
        // Default constructor has grace period = 0
        $this->request->method('getCookieParams')->willReturn([]);
        $this->cookieManager->method('getCookieName')->willReturn('session');
        $this->request->method('getHeaderLine')->with('User-Agent')->willReturn('TestAgent');
        $this->request->method('getServerParams')->willReturn(['REMOTE_ADDR' => '127.0.0.1']);

        $this->sessionManager->start($this->request);
        $this->sessionManager->set('k', 'v');

        $this->storage->expects($this->once())->method('write')->willReturn(true);
        $this->storage->expects($this->once())->method('destroy');

        $this->sessionManager->regenerateId();
    }
}
