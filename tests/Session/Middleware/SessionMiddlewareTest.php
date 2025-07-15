<?php

declare(strict_types=1);

namespace Dzentota\Session\Tests\Session\Middleware;

use Dzentota\Session\Cookie\CookieManagerInterface;
use Dzentota\Session\Middleware\SessionMiddleware;
use Dzentota\Session\SessionManager;
use Dzentota\Session\SessionState;
use Dzentota\Session\Storage\SessionStorageInterface;
use Dzentota\Session\Value\SessionId;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class SessionMiddlewareTest extends TestCase
{
    private SessionStorageInterface $storage;
    private CookieManagerInterface $cookieManager;
    private SessionMiddleware $middleware;
    private ServerRequestInterface $request;
    private ResponseInterface $response;
    private RequestHandlerInterface $handler;

    protected function setUp(): void
    {
        // Create mocks for dependencies
        $this->storage = $this->createMock(SessionStorageInterface::class);
        $this->cookieManager = $this->createMock(CookieManagerInterface::class);
        $this->request = $this->createMock(ServerRequestInterface::class);
        $this->response = $this->createMock(ResponseInterface::class);
        $this->handler = $this->createMock(RequestHandlerInterface::class);

        // Create the middleware instance with session configuration disabled
        $this->middleware = new SessionMiddleware(
            $this->storage,
            $this->cookieManager,
            1800, // 30 minutes idle timeout
            14400, // 4 hours absolute timeout
            true, // bind to IP
            true, // bind to user agent
            false // disable PHP session configuration in tests
        );
    }

    public function testProcessSetsSessionManagerAttributeOnRequest(): void
    {
        // Set up mocked behavior
        $sessionId = SessionId::generate();
        $state = new SessionState($sessionId);

        // Mock request behavior
        $this->request->method('getCookieParams')->willReturn([]);
        $this->request->method('getServerParams')->willReturn(['REMOTE_ADDR' => '127.0.0.1']);
        $this->request->method('getHeaderLine')->with('User-Agent')->willReturn('Test User Agent');

        // Создаем объект-заглушку для имитации запроса с атрибутом
        $requestWithAttribute = $this->createMock(ServerRequestInterface::class);
        $sessionManagerMock = $this->createMock(SessionManager::class);

        // Настраиваем поведение getAtribute для возврата мока SessionManager
        $requestWithAttribute->method('getAttribute')
            ->with(SessionMiddleware::SESSION_ATTRIBUTE)
            ->willReturn($sessionManagerMock);

        // Set withAttribute behavior to return our modified request mock
        $this->request->expects($this->once())
            ->method('withAttribute')
            ->with(
                $this->equalTo(SessionMiddleware::SESSION_ATTRIBUTE),
                $this->isInstanceOf(SessionManager::class)
            )
            ->willReturn($requestWithAttribute);

        // Mock the handler behavior to receive the request with attribute
        $this->handler->expects($this->once())
            ->method('handle')
            ->with($this->identicalTo($requestWithAttribute))
            ->willReturn($this->response);

        // Mock response for commit
        $this->response->method('withHeader')->willReturnSelf();

        // Execute the middleware
        $result = $this->middleware->process($this->request, $this->handler);

        // Verify the result
        $this->assertSame($this->response, $result);
    }

    public function testProcessAddsSessionCookieToResponse(): void
    {
        // Setup cookie header
        $cookieHeader = 'session=abc123; Path=/; HttpOnly; SameSite=Lax; Max-Age=3600';

        // Mock request behavior
        $this->request->method('getCookieParams')->willReturn([]);
        $this->request->method('getServerParams')->willReturn(['REMOTE_ADDR' => '127.0.0.1']);
        $this->request->method('getHeaderLine')->with('User-Agent')->willReturn('Test User Agent');
        $this->request->method('withAttribute')->willReturnSelf();

        // Mock handler to return a basic response
        $this->handler->method('handle')->willReturn($this->response);

        // Mock cookie manager to return cookie header
        $this->cookieManager->expects($this->once())
            ->method('getCookieHeader')
            ->willReturn($cookieHeader);

        // Response should have cookie header added
        $this->response->expects($this->once())
            ->method('withHeader')
            ->with('Set-Cookie', $cookieHeader)
            ->willReturnSelf();

        // Execute the middleware
        $result = $this->middleware->process($this->request, $this->handler);

        // Verify result
        $this->assertSame($this->response, $result);
    }

    public function testProcessWithExistingSession(): void
    {
        // Create a valid session ID
        $sessionId = SessionId::generate();
        $sessionData = serialize([
            '_created_at' => '2025-07-15T10:00:00+00:00',
            '_last_activity_at' => '2025-07-15T10:00:00+00:00',
            'test_key' => 'test_value'
        ]);

        // Mock cookie name
        $this->cookieManager->method('getCookieName')->willReturn('session');

        // Mock request with existing cookie
        $this->request->method('getCookieParams')->willReturn(['session' => $sessionId->toNative()]);
        $this->request->method('getServerParams')->willReturn(['REMOTE_ADDR' => '127.0.0.1']);
        $this->request->method('getHeaderLine')->with('User-Agent')->willReturn('Test User Agent');
        $this->request->method('withAttribute')->willReturnSelf();

        // Mock storage to return session data
        $this->storage->expects($this->once())
            ->method('read')
            ->with($this->callback(function($arg) use ($sessionId) {
                return $arg->toNative() === $sessionId->toNative();
            }))
            ->willReturn($sessionData);

        // Mock handler
        $this->handler->method('handle')->willReturn($this->response);

        // Mock response
        $this->response->method('withHeader')->willReturnSelf();

        // Execute middleware
        $result = $this->middleware->process($this->request, $this->handler);

        // Verify result
        $this->assertSame($this->response, $result);
    }

    public function testTimeoutBehavior(): void
    {
        // This test would require setting up specific time-based scenarios
        // which is difficult in a unit test. In a real test environment,
        // you might use a time-mocking library or dependency injection
        // for the time source. Here's a placeholder.

        $this->markTestIncomplete(
            'This test requires mocking time, which is beyond the scope of a basic test.'
        );
    }

    public function testCustomConfiguration(): void
    {
        // Test with different configuration
        $middleware = new SessionMiddleware(
            $this->storage,
            $this->cookieManager,
            3600,  // 1 hour idle timeout
            86400, // 24 hour absolute timeout
            false, // don't bind to IP
            false, // don't bind to user agent
            false  // disable PHP session configuration in tests
        );

        // Mock behavior
        $this->request->method('getCookieParams')->willReturn([]);
        $this->request->method('withAttribute')->willReturnSelf();
        $this->handler->method('handle')->willReturn($this->response);
        $this->response->method('withHeader')->willReturnSelf();

        // Execute middleware
        $result = $middleware->process($this->request, $this->handler);

        // Verify result
        $this->assertSame($this->response, $result);
    }
}
