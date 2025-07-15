<?php

declare(strict_types=1);

namespace Dzentota\Session\Tests\Session\Storage;

use Dzentota\Session\Storage\DatabaseStorage;
use Dzentota\Session\Value\SessionId;
use PHPUnit\Framework\TestCase;
use PDO;
use PDOStatement;

class DatabaseStorageTest extends TestCase
{
    private PDO $pdo;
    private DatabaseStorage $storage;
    private SessionId $sessionId;
    private string $sessionData;
    private string $tableName = 'sessions';

    protected function setUp(): void
    {
        $this->pdo = $this->createMock(PDO::class);
        $this->storage = new DatabaseStorage($this->pdo, $this->tableName);
        $this->sessionId = SessionId::generate();
        $this->sessionData = serialize(['test_key' => 'test_value']);
    }

    public function testRead(): void
    {
        $stmt = $this->createMock(PDOStatement::class);

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains("SELECT session_data FROM {$this->tableName}"))
            ->willReturn($stmt);

        $stmt->expects($this->exactly(2))
            ->method('bindValue')
            ->withConsecutive(
                [':id', $this->sessionId->toNative(), PDO::PARAM_STR],
                [':now', $this->anything(), PDO::PARAM_INT]
            );

        $stmt->expects($this->once())
            ->method('execute');

        $stmt->expects($this->once())
            ->method('fetchColumn')
            ->willReturn($this->sessionData);

        $result = $this->storage->read($this->sessionId);
        $this->assertEquals($this->sessionData, $result);
    }

    public function testReadNonExistent(): void
    {
        $stmt = $this->createMock(PDOStatement::class);

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($stmt);

        $stmt->method('execute');
        $stmt->method('fetchColumn')->willReturn(false);

        $result = $this->storage->read($this->sessionId);
        $this->assertNull($result);
    }

    public function testWriteUpdate(): void
    {
        $updateStmt = $this->createMock(PDOStatement::class);

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains("UPDATE {$this->tableName}"))
            ->willReturn($updateStmt);

        $updateStmt->expects($this->exactly(3))
            ->method('bindValue')
            ->withConsecutive(
                [':id', $this->sessionId->toNative(), PDO::PARAM_STR],
                [':data', $this->sessionData, PDO::PARAM_LOB],
                [':expires', $this->anything(), PDO::PARAM_INT]
            );

        $updateStmt->expects($this->once())
            ->method('execute');

        $updateStmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(1); // Одна строка обновлена

        $result = $this->storage->write($this->sessionId, $this->sessionData, 3600);
        $this->assertTrue($result);
    }

    public function testWriteInsert(): void
    {
        $updateStmt = $this->createMock(PDOStatement::class);
        $insertStmt = $this->createMock(PDOStatement::class);

        $this->pdo->expects($this->exactly(2))
            ->method('prepare')
            ->willReturnOnConsecutiveCalls($updateStmt, $insertStmt);

        $updateStmt->expects($this->once())
            ->method('execute');

        $updateStmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(0); // Нет обновленных строк, нужна вставка

        $insertStmt->expects($this->exactly(4))
            ->method('bindValue')
            ->withConsecutive(
                [':id', $this->sessionId->toNative(), PDO::PARAM_STR],
                [':data', $this->sessionData, PDO::PARAM_LOB],
                [':expires', $this->anything(), PDO::PARAM_INT],
                [':created', $this->anything(), PDO::PARAM_INT]
            );

        $insertStmt->expects($this->once())
            ->method('execute');

        $result = $this->storage->write($this->sessionId, $this->sessionData, 3600);
        $this->assertTrue($result);
    }

    public function testDestroy(): void
    {
        $stmt = $this->createMock(PDOStatement::class);

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains("DELETE FROM {$this->tableName}"))
            ->willReturn($stmt);

        $stmt->expects($this->once())
            ->method('bindValue')
            ->with(':id', $this->sessionId->toNative(), PDO::PARAM_STR);

        $stmt->expects($this->once())
            ->method('execute');

        $result = $this->storage->destroy($this->sessionId);
        $this->assertTrue($result);
    }

    public function testGc(): void
    {
        $stmt = $this->createMock(PDOStatement::class);

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains("DELETE FROM {$this->tableName}"))
            ->willReturn($stmt);

        $stmt->expects($this->once())
            ->method('bindValue')
            ->with(':time', $this->anything(), PDO::PARAM_INT);

        $stmt->expects($this->once())
            ->method('execute');

        $result = $this->storage->gc(3600);
        $this->assertTrue($result);
    }

    public function testEncryptionDecryption(): void
    {
        $encryptedData = 'encrypted:' . $this->sessionData;

        // Создаем хранилище с функциями шифрования и дешифрования
        $storageWithEncryption = new DatabaseStorage(
            $this->pdo,
            $this->tableName,
            fn(string $data): string => 'encrypted:' . $data,
            fn(string $data): string => substr($data, 10)
        );

        // Тест записи с шифрованием
        $stmt = $this->createMock(PDOStatement::class);
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($stmt);

        $stmt->expects($this->exactly(3))
            ->method('bindValue')
            ->withConsecutive(
                [':id', $this->sessionId->toNative(), PDO::PARAM_STR],
                [':data', $encryptedData, PDO::PARAM_LOB],
                [':expires', $this->anything(), PDO::PARAM_INT]
            );

        $stmt->method('execute');
        $stmt->method('rowCount')->willReturn(1);

        $result = $storageWithEncryption->write($this->sessionId, $this->sessionData, 3600);
        $this->assertTrue($result);
    }
}
