<?php

declare(strict_types=1);

namespace Dzentota\Session\Middleware;

use Dzentota\Session\Cookie\CookieManagerInterface;
use Dzentota\Session\SessionManager;
use Dzentota\Session\Storage\SessionStorageInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PSR-15 middleware for session management
 */
class SessionMiddleware implements MiddlewareInterface
{
    /**
     * @var string The attribute name for storing the session manager in the request
     */
    public const SESSION_ATTRIBUTE = 'session';

    /**
     * @param SessionStorageInterface $storage Storage adapter for session data
     * @param CookieManagerInterface $cookieManager Cookie manager for session cookies
     * @param int $idleTimeout Idle timeout in seconds (default: 30 minutes)
     * @param int $absoluteTimeout Absolute timeout in seconds (default: 4 hours)
     * @param bool $bindToIp Whether to bind session to IP address
     * @param bool $bindToUserAgent Whether to bind session to User-Agent header
     * @param bool $configureSession Whether to configure PHP session (set to false in tests)
     */
    public function __construct(
        private SessionStorageInterface $storage,
        private CookieManagerInterface $cookieManager,
        private int $idleTimeout = 1800,
        private int $absoluteTimeout = 14400,
        private bool $bindToIp = true,
        private bool $bindToUserAgent = true,
        private bool $configureSession = true
    ) {
        // Ensure PHP's native session handling doesn't interfere
        if (PHP_SESSION_ACTIVE === session_status()) {
            session_write_close();
        }

        // Настраиваем параметры сессии сразу, если разрешено
        if ($this->configureSession) {
            $this->configureSessionSettings();
        }
    }

    /**
     * Process a server request and return a response
     */
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        // Create session manager
        $sessionManager = new SessionManager(
            $this->storage,
            $this->cookieManager,
            $this->idleTimeout,
            $this->absoluteTimeout,
            $this->bindToIp,
            $this->bindToUserAgent
        );

        // Start the session
        $sessionManager->start($request);

        // Add session manager to request attributes
        $request = $request->withAttribute(self::SESSION_ATTRIBUTE, $sessionManager);

        // Pass to next middleware
        $response = $handler->handle($request);

        // Commit session changes and add cookie to response
        return $sessionManager->commit($response);
    }

    /**
     * Configure PHP session settings
     */
    private function configureSessionSettings(): void
    {
        // Disable PHP's automatic cookie handling
        ini_set('session.use_cookies', '0');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.use_only_cookies', '0');
    }
}
