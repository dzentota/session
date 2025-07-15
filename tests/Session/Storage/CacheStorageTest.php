<?php

declare(strict_types=1);

namespace Dzentota\Session\Tests\Session\Storage;

use Dzentota\Session\Storage\CacheStorage;
use Dzentota\Session\Value\SessionId;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;

class CacheStorageTest extends TestCase
{
    private CacheInterface $cache;
    private CacheStorage $storage;
    private SessionId $sessionId;
    private string $sessionData;
    private string $cacheKey;

    protected function setUp(): void
    {
        $this->cache = $this->createMock(CacheInterface::class);
        $this->storage = new CacheStorage($this->cache, 'session_');
        $this->sessionId = SessionId::generate();
        $this->sessionData = serialize(['test_key' => 'test_value']);
        $this->cacheKey = 'session_' . $this->sessionId->toNative();
    }

    public function testReadExistingSession(): void
    {
        $this->cache->expects($this->once())
            ->method('get')
            ->with($this->cacheKey)
            ->willReturn($this->sessionData);

        $result = $this->storage->read($this->sessionId);
        $this->assertEquals($this->sessionData, $result);
    }

    public function testReadNonExistentSession(): void
    {
        $this->cache->expects($this->once())
            ->method('get')
            ->with($this->cacheKey)
            ->willReturn(null);

        $result = $this->storage->read($this->sessionId);
        $this->assertNull($result);
    }

    public function testWrite(): void
    {
        $ttl = 3600;

        $this->cache->expects($this->once())
            ->method('set')
            ->with(
                $this->cacheKey,
                $this->sessionData,
                $ttl
            )
            ->willReturn(true);

        $result = $this->storage->write($this->sessionId, $this->sessionData, $ttl);
        $this->assertTrue($result);
    }

    public function testDestroy(): void
    {
        $this->cache->expects($this->once())
            ->method('delete')
            ->with($this->cacheKey)
            ->willReturn(true);

        $result = $this->storage->destroy($this->sessionId);
        $this->assertTrue($result);
    }

    public function testGc(): void
    {
        // CacheStorage не требует явной сборки мусора, так как кеш управляет этим автоматически
        $result = $this->storage->gc(3600);
        $this->assertTrue($result);
    }

    public function testEncryptionDecryption(): void
    {
        $encryptedData = 'encrypted:' . $this->sessionData;

        // Создаем хранилище с функциями шифрования и дешифрования
        $storageWithEncryption = new CacheStorage(
            $this->cache,
            'session_',
            fn(string $data): string => 'encrypted:' . $data,
            fn(string $data): string => substr($data, 10)
        );

        // Тест записи с шифрованием
        $this->cache->expects($this->once())
            ->method('set')
            ->with(
                $this->cacheKey,
                $encryptedData,
                3600
            )
            ->willReturn(true);

        $result = $storageWithEncryption->write($this->sessionId, $this->sessionData, 3600);
        $this->assertTrue($result);

        // Тест чтения с дешифрованием
        $this->cache->expects($this->once())
            ->method('get')
            ->with($this->cacheKey)
            ->willReturn($encryptedData);

        $result = $storageWithEncryption->read($this->sessionId);
        $this->assertEquals($this->sessionData, $result);
    }

    public function testCustomPrefix(): void
    {
        $customPrefix = 'custom_prefix_';
        $customCacheKey = $customPrefix . $this->sessionId->toNative();

        $storageWithCustomPrefix = new CacheStorage($this->cache, $customPrefix);

        // Проверяем, что используется правильный префикс при чтении
        $this->cache->expects($this->once())
            ->method('get')
            ->with($customCacheKey)
            ->willReturn($this->sessionData);

        $result = $storageWithCustomPrefix->read($this->sessionId);
        $this->assertEquals($this->sessionData, $result);
    }
}
