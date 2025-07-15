<?php

declare(strict_types=1);

namespace Dzentota\Session\Exception;

/**
 * Exception thrown when an invalid CSRF token is provided
 */
class InvalidTokenException extends \InvalidArgumentException
{
}
