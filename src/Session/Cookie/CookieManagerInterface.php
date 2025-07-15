<?php

declare(strict_types=1);

namespace Dzentota\Session\Cookie;

use Dzentota\Session\SessionState;

/**
 * Interface for session cookie management
 */
interface CookieManagerInterface
{
    /**
     * Generate a Set-Cookie header based on the session state
     *
     * @param SessionState $state Current session state
     * @return string|null The Set-Cookie header value, or null if no cookie should be sent
     */
    public function getCookieHeader(SessionState $state): ?string;

    /**
     * Get the cookie name used for session identification
     *
     * @return string The cookie name
     */
    public function getCookieName(): string;
}
