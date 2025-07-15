<?php

declare(strict_types=1);

namespace Dzentota\Session;

/**
 * Security utilities for session management
 */
class SessionSecurity
{
    /**
     * Check if the current connection is secure (HTTPS)
     *
     * @param array $server Server variables (typically $_SERVER)
     * @return bool True if the connection is secure
     */
    public static function isSecureConnection(array $server): bool
    {
        if (isset($server['HTTPS']) && $server['HTTPS'] !== 'off') {
            return true;
        }

        if (isset($server['HTTP_X_FORWARDED_PROTO']) && $server['HTTP_X_FORWARDED_PROTO'] === 'https') {
            return true;
        }

        if (isset($server['HTTP_X_FORWARDED_SSL']) && $server['HTTP_X_FORWARDED_SSL'] === 'on') {
            return true;
        }

        if (isset($server['SERVER_PORT']) && (int)$server['SERVER_PORT'] === 443) {
            return true;
        }

        return false;
    }

    /**
     * Generate a secure random key of specified length
     *
     * @param int $length Length in bytes
     * @return string Random bytes as a binary string
     */
    public static function generateRandomBytes(int $length): string
    {
        return random_bytes($length);
    }

    /**
     * Get a secure hash of an IP address to avoid storing PII directly
     *
     * @param string $ip IP address to hash
     * @param string $salt Optional salt for the hash
     * @return string Hashed IP
     */
    public static function hashIpAddress(string $ip, string $salt = ''): string
    {
        if (empty($salt)) {
            $salt = 'dzentota-session-ip-binding';
        }
        return hash('sha256', $ip . $salt);
    }

    /**
     * Extract the client IP address from server variables
     *
     * @param array $server Server variables (typically $_SERVER)
     * @return string Client IP address
     */
    public static function getClientIp(array $server): string
    {
        // Try various headers that might contain the real IP
        $headers = [
            'HTTP_X_FORWARDED_FOR',
            'HTTP_CLIENT_IP',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR'
        ];

        foreach ($headers as $header) {
            if (isset($server[$header])) {
                // X-Forwarded-For may contain multiple IPs, use the first one
                if ($header === 'HTTP_X_FORWARDED_FOR') {
                    $ips = explode(',', $server[$header]);
                    return trim($ips[0]);
                }
                return $server[$header];
            }
        }

        return '0.0.0.0';
    }

    /**
     * Compare two strings in a timing-safe way to prevent timing attacks
     *
     * @param string $known Known string
     * @param string $user User-provided string
     * @return bool True if strings are equal
     */
    public static function safeCompare(string $known, string $user): bool
    {
        return hash_equals($known, $user);
    }
}
