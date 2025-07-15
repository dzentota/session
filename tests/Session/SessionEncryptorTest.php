<?php

declare(strict_types=1);

namespace Dzentota\Session\Tests\Session;

use Dzentota\Session\SessionEncryptor;
use PHPUnit\Framework\TestCase;

class SessionEncryptorTest extends TestCase
{
    private string $key;
    private SessionEncryptor $encryptor;

    protected function setUp(): void
    {
        // Using a predictable key for tests
        $this->key = str_repeat('a', 32); // 32 bytes
        $this->encryptor = new SessionEncryptor($this->key);
    }

    public function testEncryptDecrypt(): void
    {
        $originalData = 'This is sensitive session data';

        // Encrypt data
        $encrypted = $this->encryptor->encrypt($originalData);

        // Check that encrypted data doesn't match the original
        $this->assertNotEquals($originalData, $encrypted);

        // Decrypt data
        $decrypted = $this->encryptor->decrypt($encrypted);

        // Check that data was successfully restored
        $this->assertEquals($originalData, $decrypted);
    }

    public function testEncryptionWithEmptyString(): void
    {
        $originalData = '';

        // Encrypt an empty string
        $encrypted = $this->encryptor->encrypt($originalData);

        // Check that the result is not an empty string (contains IV, tag and empty encrypted data)
        $this->assertNotEmpty($encrypted);

        // Decrypt data
        $decrypted = $this->encryptor->decrypt($encrypted);

        // Check that we got an empty string back
        $this->assertEquals('', $decrypted);
    }

    public function testEncryptionWithBinaryData(): void
    {
        $originalData = random_bytes(100); // 100 random bytes

        // Encrypt binary data
        $encrypted = $this->encryptor->encrypt($originalData);

        // Decrypt data
        $decrypted = $this->encryptor->decrypt($encrypted);

        // Check that binary data was successfully restored
        $this->assertEquals($originalData, $decrypted);
    }

    public function testInvalidKeyLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Encryption key must be at least 32 bytes');

        // Attempt to create encryptor with short key
        new SessionEncryptor('short_key');
    }

    public function testDecryptionWithInvalidData(): void
    {
        $this->expectException(\RuntimeException::class);

        // Attempt to decrypt invalid data
        $this->encryptor->decrypt('not-valid-base64-data!@#$');
    }

    public function testDecryptionWithTooShortData(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Decryption failed: data too short');

        // Encrypt data
        $encrypted = $this->encryptor->encrypt('test');

        // Truncate encrypted data to make it too short
        $truncated = substr(base64_decode($encrypted), 0, 10);

        // Attempt to decrypt truncated data
        $this->encryptor->decrypt(base64_encode($truncated));
    }

    public function testGenerateKey(): void
    {
        $key = SessionEncryptor::generateKey();

        // Check that the key has the correct length
        $this->assertEquals(32, strlen($key));

        // Check that repeated calls generate different keys
        $key2 = SessionEncryptor::generateKey();
        $this->assertNotEquals($key, $key2);
    }
}
