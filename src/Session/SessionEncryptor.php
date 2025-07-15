<?php

declare(strict_types=1);

namespace Dzentota\Session;

/**
 * Utility class for encrypting and decrypting session data
 */
class SessionEncryptor
{
    /**
     * The encryption algorithm to use
     */
    private const CIPHER = 'aes-256-gcm';

    /**
     * Authentication tag length for GCM mode
     */
    private const TAG_LENGTH = 16;

    /**
     * @param string $key Encryption key (should be securely generated and stored)
     */
    public function __construct(
        private string $key
    ) {
        if (strlen($key) < 32) {
            throw new \InvalidArgumentException('Encryption key must be at least 32 bytes');
        }

        if (!in_array(self::CIPHER, openssl_get_cipher_methods())) {
            throw new \RuntimeException(self::CIPHER . ' cipher is not available');
        }
    }

    /**
     * Encrypt session data
     *
     * @param string $data Plain data to encrypt
     * @return string Encrypted data
     */
    public function encrypt(string $data): string
    {
        // Generate a random IV for each encryption
        $iv = random_bytes(openssl_cipher_iv_length(self::CIPHER));

        // Initialize authentication tag variable
        $tag = '';

        // Encrypt the data with AEAD mode (Authenticated Encryption with Associated Data)
        $encrypted = openssl_encrypt(
            $data,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '', // No additional authenticated data
            self::TAG_LENGTH
        );

        if ($encrypted === false) {
            throw new \RuntimeException('Encryption failed: ' . openssl_error_string());
        }

        // Combine IV, authentication tag, and ciphertext for storage
        $result = $iv . $tag . $encrypted;

        // Base64 encode for safe storage
        return base64_encode($result);
    }

    /**
     * Decrypt session data
     *
     * @param string $encryptedData Base64-encoded encrypted data
     * @return string Decrypted data
     * @throws \RuntimeException If decryption fails
     */
    public function decrypt(string $encryptedData): string
    {
        // Decode from base64
        $combined = base64_decode($encryptedData, true);
        if ($combined === false) {
            throw new \RuntimeException('Decryption failed: invalid base64 data');
        }

        // Get the IV length for the cipher
        $ivLength = openssl_cipher_iv_length(self::CIPHER);

        // Ensure we have enough data for IV and tag
        $minLength = $ivLength + self::TAG_LENGTH; // We now allow empty encrypted data
        if (strlen($combined) < $minLength) {
            throw new \RuntimeException('Decryption failed: data too short');
        }

        // Extract the IV, tag, and encrypted data
        $iv = substr($combined, 0, $ivLength);
        $tag = substr($combined, $ivLength, self::TAG_LENGTH);
        $ciphertext = substr($combined, $ivLength + self::TAG_LENGTH);

        // Decrypt the data
        $decrypted = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($decrypted === false) {
            throw new \RuntimeException('Decryption failed: authentication failed');
        }

        return $decrypted;
    }

    /**
     * Generate a secure random encryption key
     *
     * @return string Random encryption key
     */
    public static function generateKey(): string
    {
        return random_bytes(32); // 256 bits
    }
}
